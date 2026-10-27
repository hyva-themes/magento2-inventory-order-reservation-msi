<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\RepairNeededException;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Hyva\InventoryOrderReservationMsi\Model\Handler\InventoryHandler;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterface;
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

class InventoryHandlerTest extends TestCase
{
    /** @var array<int, array{0: array, 1: array, 2: array}> what the sales-event service was given: items, channel, event */
    private array $placed = [];

    protected function setUp(): void
    {
        $this->placed = [];
    }

    public function test_one_item_to_sell_per_sku_at_what_it_still_owes_under_an_order_placed_event(): void
    {
        $handler = $this->handler(['mug' => -2.0, 'kit' => -1.0, 'free' => 0.0]);
        $owed = [
            new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'kit', 'bundle', 1.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'free', 'simple', 0.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        $handler->register($this->order(9, '000000009'), $owed);

        self::assertCount(1, $this->placed);
        [$items, $channel, $event] = $this->placed[0];
        // the remainder is read per sku on the recorded stock; Magento's
        // placement reads each sku's type from the catalog, so nothing is filtered here
        self::assertSame([['sku' => 'mug', 'qty' => -2.0], ['sku' => 'kit', 'qty' => -1.0]], $items);
        self::assertSame(['type' => 'website', 'code' => 'base'], $channel);
        self::assertSame(['type' => 'order_placed', 'objectType' => 'order', 'objectId' => '9', 'objectIncrementId' => '000000009'], $event);
    }

    public function test_a_retry_after_a_partial_compensation_places_only_what_is_still_missing(): void
    {
        // three ordered, one compensated by hand while the order was given up on: two still missing
        $handler = $this->handler(['mug' => -2.0]);

        $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame([['sku' => 'mug', 'qty' => -2.0]], $this->placed[0][0], 'the two still missing, not the three ordered');
    }

    public function test_an_order_whose_rows_already_balance_places_nothing(): void
    {
        // shipped, canceled or repaired in full before the registration ran
        $handler = $this->handler(['mug' => 0.0]);

        $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame([], $this->placed, 'nothing to place, nothing written');
    }

    public function test_a_sku_reserved_past_what_the_order_owes_is_given_up_on_for_repair(): void
    {
        // three ordered, four on the rows: a placement cannot take a unit back
        $handler = $this->handler(['cup' => -2.0, 'mug' => 1.0]);
        $owed = [
            new Obligation(9, 'cup', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        try {
            $handler->register($this->order(9, '000000009'), $owed);
            self::fail('a surplus must be given up on');
        } catch (RepairNeededException $e) {
            self::assertSame('Sku mug is reserved +1.0000 past what the order owes on stock 2; nothing to place, a compensation of +1.0000 repairs it.', $e->getMessage());
        }
        self::assertSame([], $this->placed, 'nothing placed for any sku: the order is given up on as a whole');
    }

    public function test_an_obligation_without_a_stock_is_refused(): void
    {
        $handler = $this->handler([]);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('names no stock');
        $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, null, Obligation::MODE_INVENTORY)]);
    }

    /**
     * The handler over Inventory's sales-event service and its factories, recording what is placed, with each sku's remainder on stock 2 as given.
     *
     * @param array<string, float> $remainingBySku
     */
    private function handler(array $remainingBySku): InventoryHandler
    {
        $incrementId = null;
        $place = $this->createMock(PlaceReservationsForSalesEventInterface::class);
        $place->method('execute')->willReturnCallback(
            function (array $items, SalesChannelInterface $channel, SalesEventInterface $event) use (&$incrementId): void {
                $this->placed[] = [
                    array_map(static fn (ItemToSellInterface $item): array => ['sku' => $item->getSku(), 'qty' => $item->getQuantity()], $items),
                    ['type' => $channel->getType(), 'code' => $channel->getCode()],
                    [
                        'type' => $event->getType(),
                        'objectType' => $event->getObjectType(),
                        'objectId' => $event->getObjectId(),
                        'objectIncrementId' => $incrementId,
                    ],
                ];
            }
        );
        $items = $this->createMock(ItemToSellInterfaceFactory::class);
        $items->method('create')->willReturnCallback(function (array $data): ItemToSellInterface {
            $item = $this->createMock(ItemToSellInterface::class);
            $item->method('getSku')->willReturn($data['sku']);
            $item->method('getQuantity')->willReturn($data['qty']);
            return $item;
        });
        $channels = $this->createMock(SalesChannelInterfaceFactory::class);
        $channels->method('create')->willReturnCallback(function (array $arguments): SalesChannelInterface {
            $channel = $this->createMock(SalesChannelInterface::class);
            $channel->method('getType')->willReturn($arguments['data']['type']);
            $channel->method('getCode')->willReturn($arguments['data']['code']);
            return $channel;
        });
        $events = $this->createMock(SalesEventInterfaceFactory::class);
        $events->method('create')->willReturnCallback(function (array $data): SalesEventInterface {
            $event = $this->createMock(SalesEventInterface::class);
            $event->method('getType')->willReturn($data['type']);
            $event->method('getObjectType')->willReturn($data['objectType']);
            $event->method('getObjectId')->willReturn($data['objectId']);
            return $event;
        });
        $extensions = $this->createMock(SalesEventExtensionFactory::class);
        $extensions->method('create')->willReturnCallback(function (array $arguments) use (&$incrementId): SalesEventExtensionInterface {
            $incrementId = $arguments['data']['objectIncrementId'];
            return $this->createMock(SalesEventExtensionInterface::class);
        });
        $remaining = $this->createMock(RemainingInconsistency::class);
        $remaining->method('forOrderSku')->willReturnCallback(
            static fn (object $order, string $sku, int $stockId): array => [
                'expected' => $remainingBySku[$sku] ?? 0.0,
                'actual' => 0.0,
                'remaining' => $remainingBySku[$sku] ?? 0.0,
            ]
        );
        return new InventoryHandler($place, $items, $channels, $events, $extensions, $this->websites('base'), $remaining);
    }

    private function websites(string $code): WebsiteRepositoryInterface
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($code);
        $repository = $this->createMock(WebsiteRepositoryInterface::class);
        $repository->method('getById')->willReturn($website);
        return $repository;
    }

    private function order(int $id, string $increment): Order
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getEntityId', 'getIncrementId', 'getStore'])->getMock();
        $order->method('getEntityId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($increment);
        $order->method('getStore')->willReturn($store);
        return $order;
    }
}
