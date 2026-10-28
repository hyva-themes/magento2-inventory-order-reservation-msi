<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\RowOutcome;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ProductIdentities;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Hyva\InventoryOrderReservationMsi\Model\Handler\InventoryHandler;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\InventorySales\Model\SalesEventToArrayConverter;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class InventoryHandlerTest extends TestCase
{
    /** @var array<int, array{sku: string, qty: float, stock: int, metadata: array}> the rows given to Inventory's writer, in order */
    private array $written = [];

    /** @var int how many times the writer was called */
    private int $appends = 0;

    protected function setUp(): void
    {
        $this->written = [];
        $this->appends = 0;
    }

    public function test_one_row_per_sku_on_the_recorded_stock_with_magentos_metadata_in_one_append(): void
    {
        $handler = $this->handler(['mug' => -2.0, 'cup' => -1.0, 'free' => 0.0]);
        $owed = [
            new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'cup', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'free', 'simple', 0.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        $outcomes = $handler->register($this->order(9, '000000009'), $owed);

        self::assertSame(['mug' => 'placed', 'cup' => 'placed', 'free' => 'balanced'], $this->kinds($outcomes));
        self::assertSame(1, $this->appends, 'one append for the order');
        self::assertSame(
            [['sku' => 'mug', 'qty' => -2.0, 'stock' => 2], ['sku' => 'cup', 'qty' => -1.0, 'stock' => 2]],
            array_map(static fn (array $row): array => ['sku' => $row['sku'], 'qty' => $row['qty'], 'stock' => $row['stock']], $this->written)
        );
        self::assertSame(
            ['event_type' => 'order_placed', 'object_type' => 'order', 'object_id' => '9', 'object_increment_id' => '000000009'],
            $this->written[0]['metadata'],
            'the metadata Magento writes for a placement'
        );
    }

    public function test_the_recorded_stock_is_written_whatever_the_website_maps_to_now(): void
    {
        // recorded on stock 2; the rows name it, the website's mapping of the day is never read
        $handler = $this->handler(['mug' => -1.0]);

        $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(2, $this->written[0]['stock']);
    }

    public function test_a_retry_after_a_partial_compensation_places_only_what_is_still_missing(): void
    {
        // three ordered, one compensated by hand while the order was given up on: two still missing
        $handler = $this->handler(['mug' => -2.0]);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(-2.0, $this->written[0]['qty'], 'the two still missing, not the three ordered');
        self::assertSame(-2.0, $outcomes[0]->placed);
    }

    public function test_a_row_whose_product_was_renamed_is_refused_not_balanced(): void
    {
        // recorded as OLD for product 10, renamed to NEW before the registration ran
        $handler = $this->handler(['OLD' => 0.0, 'NEW' => 0.0], catalog: [10 => ['sku' => 'NEW', 'type' => 'simple']]);

        $outcomes = $handler->register($this->order(9, '000000009'), [
            new Obligation(9, 'OLD', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10),
        ]);

        self::assertSame(['OLD' => 'refused'], $this->kinds($outcomes));
        self::assertStringContainsString('Sku OLD is NEW in the catalog now', $outcomes[0]->reason);
        self::assertStringContainsString('inventory:order-reservation:settle 9', $outcomes[0]->reason);
        self::assertSame(0, $this->appends, 'nothing written under either sku');
    }

    public function test_a_row_whose_product_keeps_its_sku_is_placed(): void
    {
        $handler = $this->handler(['mug' => -2.0], catalog: [10 => ['sku' => 'mug', 'type' => 'simple']]);

        $outcomes = $handler->register($this->order(9, '000000009'), [
            new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10),
        ]);

        self::assertSame(['mug' => 'placed'], $this->kinds($outcomes));
    }

    public function test_an_order_whose_rows_already_balance_places_nothing(): void
    {
        // shipped, canceled or repaired in full before the registration ran
        $handler = $this->handler(['mug' => 0.0]);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(0, $this->appends, 'nothing to place, nothing written');
        self::assertSame(['mug' => 'balanced'], $this->kinds($outcomes));
    }

    public function test_a_sku_reserved_past_what_the_order_owes_is_refused_for_repair_while_the_others_are_placed(): void
    {
        // three ordered, four on the rows: a placement cannot take a unit back
        $handler = $this->handler(['cup' => -2.0, 'mug' => 1.0]);
        $owed = [
            new Obligation(9, 'cup', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'mug', 'simple', 3.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        $outcomes = $handler->register($this->order(9, '000000009'), $owed);

        self::assertSame(['cup' => 'placed', 'mug' => 'refused'], $this->kinds($outcomes));
        self::assertSame('Sku mug: stock 2 holds +1.0000 past what the order owes, a compensation of +1.0000 on it repairs that. Nothing is placed.', $outcomes[1]->reason);
        self::assertSame([2 => 1.0], $outcomes[1]->remainders);
        self::assertSame(['cup'], array_column($this->written, 'sku'), 'the other sku is placed in the same run');
    }

    public function test_a_lack_on_the_stock_the_website_moved_to_is_refused_naming_the_move_and_the_stock(): void
    {
        // recorded on stock 2, the website sells from stock 1 now, the shipment's row sits there: nothing is placed
        $handler = $this->handler([], remaindersBySku: ['mug' => [1 => -1.0, 2 => 0.0]], currentStock: 1);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(['mug' => 'refused'], $this->kinds($outcomes));
        self::assertSame(
            'Sku mug: the website sells from stock 1 now and the order was recorded on stock 2; stock 1 lacks -1.0000, a compensation of -1.0000 on it repairs that. Nothing is placed.',
            $outcomes[0]->reason
        );
        self::assertSame([1 => -1.0], $outcomes[0]->remainders);
        self::assertSame(0, $this->appends);
    }

    public function test_a_lack_on_the_recorded_stock_alone_is_placed_there_after_a_move(): void
    {
        // shipped on stock 2 before the website moved to stock 1: the placement on 2 balances the shipment
        $handler = $this->handler([], remaindersBySku: ['mug' => [1 => 0.0, 2 => -1.0]], currentStock: 1);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(['mug' => 'placed'], $this->kinds($outcomes));
        self::assertSame(2, $this->written[0]['stock']);
    }

    public function test_a_split_shipment_around_a_move_is_refused_naming_both_stocks(): void
    {
        $handler = $this->handler([], remaindersBySku: ['mug' => [1 => -1.0, 2 => -1.0]], currentStock: 1);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY)]);

        self::assertSame(['mug' => 'refused'], $this->kinds($outcomes));
        self::assertSame([1 => -1.0, 2 => -1.0], $outcomes[0]->remainders);
        self::assertStringContainsString('stock 1 lacks -1.0000', $outcomes[0]->reason);
        self::assertStringContainsString('stock 2 lacks -1.0000', $outcomes[0]->reason);
        self::assertSame(0, $this->appends, 'placing on the recorded stock alone would leave the row half repaired');
    }

    public function test_a_type_inventory_does_not_manage_is_balanced_without_a_row_and_an_unknown_sku_is_written(): void
    {
        // Magento's own filter: a bundle holds no stock of its own; a sku the catalog does not hold is written as the service writes it
        $handler = $this->handler(['kit' => -1.0, 'ghost' => -1.0], types: ['kit' => 'bundle'], managed: static fn (string $type): bool => $type !== 'bundle');
        $owed = [
            new Obligation(9, 'kit', 'bundle', 1.0, 1, 2, Obligation::MODE_INVENTORY),
            new Obligation(9, 'ghost', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY),
        ];

        $outcomes = $handler->register($this->order(9, '000000009'), $owed);

        self::assertSame(['kit' => 'balanced', 'ghost' => 'placed'], $this->kinds($outcomes));
        self::assertSame(['ghost'], array_column($this->written, 'sku'));
    }

    public function test_an_obligation_without_a_stock_is_refused(): void
    {
        $handler = $this->handler([]);

        $outcomes = $handler->register($this->order(9, '000000009'), [new Obligation(9, 'mug', 'simple', 1.0, 1, null, Obligation::MODE_INVENTORY)]);

        self::assertSame(['mug' => 'refused'], $this->kinds($outcomes));
        self::assertStringContainsString('assigned to no stock', $outcomes[0]->reason);
        self::assertSame(0, $this->appends);
    }

    /**
     * The handler over Inventory's builder and writer, recording the rows, with each sku's remainder on its recorded stock as given; every sku a managed simple product unless said otherwise.
     *
     * @param array<string, float> $remainingBySku the remainder on the recorded stock 2, the one stock that matters, per sku
     * @param array<string, string> $types the catalog's type per sku; a sku not named is not in the catalog
     * @param callable(string): bool|null $managed whether a type allows source-item management
     * @param array<string, array<int, float>> $remaindersBySku the remainders per stock for a sku, when more than one stock matters
     * @param int $currentStock the stock the website sells from now
     */
    private function handler(array $remainingBySku, array $types = [], ?callable $managed = null, array $remaindersBySku = [], int $currentStock = 2, array $catalog = []): InventoryHandler
    {
        $builder = $this->createStub(ReservationBuilderInterface::class);
        $row = [];
        $builder->method('setSku')->willReturnCallback(function (string $sku) use (&$row, $builder): ReservationBuilderInterface {
            $row['sku'] = $sku;
            return $builder;
        });
        $builder->method('setQuantity')->willReturnCallback(function (float $qty) use (&$row, $builder): ReservationBuilderInterface {
            $row['qty'] = $qty;
            return $builder;
        });
        $builder->method('setStockId')->willReturnCallback(function (int $stock) use (&$row, $builder): ReservationBuilderInterface {
            $row['stock'] = $stock;
            return $builder;
        });
        $builder->method('setMetadata')->willReturnCallback(function (?string $metadata) use (&$row, $builder): ReservationBuilderInterface {
            $row['metadata'] = json_decode((string) $metadata, true);
            return $builder;
        });
        $builder->method('build')->willReturnCallback(function () use (&$row): ReservationInterface {
            $reservation = $this->createStub(ReservationInterface::class);
            $reservation->method('getSku')->willReturn($row['sku']);
            $reservation->method('getQuantity')->willReturn($row['qty']);
            $reservation->method('getStockId')->willReturn($row['stock']);
            $reservation->method('getMetadata')->willReturn(json_encode($row['metadata']));
            $built = $row;
            $row = [];
            $this->written[] = $built;
            return $reservation;
        });
        $writer = $this->createStub(AppendReservationsInterface::class);
        $writer->method('execute')->willReturnCallback(function (array $reservations): void {
            $this->appends++;
        });
        $events = $this->createStub(SalesEventInterfaceFactory::class);
        $events->method('create')->willReturnCallback(function (array $data): SalesEventInterface {
            $event = $this->createStub(SalesEventInterface::class);
            $event->method('getType')->willReturn($data['type']);
            $event->method('getObjectType')->willReturn($data['objectType']);
            $event->method('getObjectId')->willReturn($data['objectId']);
            return $event;
        });
        $incrementId = null;
        $extensions = $this->createStub(SalesEventExtensionFactory::class);
        $extensions->method('create')->willReturnCallback(function (array $arguments) use (&$incrementId): SalesEventExtensionInterface {
            $incrementId = $arguments['data']['objectIncrementId'];
            return $this->createStub(SalesEventExtensionInterface::class);
        });
        $converter = $this->createStub(SalesEventToArrayConverter::class);
        $converter->method('execute')->willReturnCallback(static function (SalesEventInterface $event) use (&$incrementId): array {
            return [
                'event_type' => $event->getType(),
                'object_type' => $event->getObjectType(),
                'object_id' => $event->getObjectId(),
                'object_increment_id' => $incrementId ?? '',
            ];
        });
        $productTypes = $this->createStub(GetProductTypesBySkusInterface::class);
        $productTypes->method('execute')->willReturnCallback(
            static fn (array $skus): array => array_intersect_key($types + array_fill_keys($skus, 'simple'), array_flip($skus)) + ($types === [] ? array_fill_keys($skus, 'simple') : [])
        );
        if ($types !== []) {
            $productTypes = $this->createStub(GetProductTypesBySkusInterface::class);
            $productTypes->method('execute')->willReturnCallback(static fn (array $skus): array => array_intersect_key($types, array_flip($skus)));
        }
        $allowed = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $allowed->method('execute')->willReturnCallback(static fn (string $type): bool => $managed === null || $managed($type));
        $remaining = $this->createStub(RemainingInconsistency::class);
        $remaining->method('remaindersForRow')->willReturnCallback(
            static fn (object $order, string $sku, int $recordedStock): array => $remaindersBySku[$sku] ?? [$recordedStock => $remainingBySku[$sku] ?? 0.0]
        );
        $remaining->method('currentStockId')->willReturn($currentStock);
        $products = $this->createStub(ProductIdentities::class);
        $products->method('byProductIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($catalog, array_flip($ids))
        );
        return new InventoryHandler($builder, $writer, $events, $extensions, $converter, new Json(), $productTypes, $allowed, $remaining, $products);
    }

    /**
     * @param RowOutcome[] $outcomes
     * @return array<string, string> sku => kind
     */
    private function kinds(array $outcomes): array
    {
        $kinds = [];
        foreach ($outcomes as $outcome) {
            $kinds[$outcome->row->sku] = $outcome->kind;
        }
        return $kinds;
    }

    private function order(int $id, string $increment): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getIncrementId')->willReturn($increment);
        return $order;
    }
}
