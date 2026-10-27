<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Compensation;

use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RemainingInconsistencyTest extends TestCase
{
    public function test_a_final_order_expects_zero(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, [$this->item('mug', 'simple', 3, 0, 0, 3, 3, false)]);
        self::assertSame(0.0, $this->service()->expectedSum($order, 'mug'));
    }

    public function test_an_open_order_expects_minus_what_is_neither_canceled_refunded_nor_shipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [
            $this->item('mug', 'simple', 5, 1, 1, 1, 0, false),
            $this->item('mug', 'simple', 2, 0, 0, 0, 0, false),
            $this->item('other', 'simple', 9, 0, 0, 0, 0, false),
        ]);
        // 5 - 1 - 1 - 1 = 2, plus 2 untouched: minus 4
        self::assertSame(-4.0, $this->service()->expectedSum($order, 'mug'));
    }

    public function test_a_virtual_line_counts_what_is_invoiced_instead_of_shipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('ebook', 'virtual', 2, 0, 0, 0, 2, true)]);
        self::assertSame(0.0, $this->service()->expectedSum($order, 'ebook'));
    }

    public function test_a_type_inventory_does_not_manage_is_left_out(): void
    {
        $managed = new class {
            public function execute(string $type): bool
            {
                return $type !== 'configurable';
            }
        };
        $order = $this->order(Order::STATE_PROCESSING, [
            $this->item('shirt', 'configurable', 1, 0, 0, 0, 0, false),
            $this->item('shirt', 'simple', 1, 0, 0, 0, 0, false),
        ]);
        self::assertSame(-1.0, $this->service($managed)->expectedSum($order, 'shirt'));
    }

    #[Test]
    public function a_configurable_child_follows_its_parents_shipped_quantity(): void
    {
        // two ordered, one shipped through the parent: the child's own qty_shipped stays 0 in Magento
        $parent = $this->item('shirt-blue', 'configurable', 2.0, 0.0, 0.0, 1.0, 2.0, false);
        $child = $this->item('shirt-blue', 'simple', 2.0, 0.0, 0.0, 0.0, 0.0, false);
        $parent->setItemId(100);
        $child->setParentItemId(100);
        $child->setParentItem($parent);
        $service = $this->service($this->managing(['simple']));

        self::assertSame(-1.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-blue'));
    }

    #[Test]
    public function a_child_of_a_bundle_that_ships_separately_keeps_its_own_progress(): void
    {
        $parent = $this->item('bundle', 'bundle', 1.0, 0.0, 0.0, 1.0, 1.0, false);
        $parent->setItemId(200);
        $parent->setProductOptions(['shipment_type' => \Magento\Catalog\Model\Product\Type\AbstractType::SHIPMENT_SEPARATELY]);
        $child = $this->item('bundle-part', 'simple', 3.0, 0.0, 0.0, 1.0, 3.0, false);
        $child->setParentItemId(200);
        $child->setParentItem($parent);
        $service = $this->service($this->managing(['simple']));

        self::assertSame(-2.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'bundle-part'));
    }

    #[Test]
    public function a_canceled_and_refunded_parent_projects_both_onto_its_child(): void
    {
        $parent = $this->item('shirt-red', 'configurable', 4.0, 1.0, 1.0, 2.0, 3.0, false);
        $parent->setItemId(300);
        $child = $this->item('shirt-red', 'simple', 4.0, 0.0, 0.0, 0.0, 0.0, false);
        $child->setParentItemId(300);
        $child->setParentItem($parent);
        $service = $this->service($this->managing(['simple']));

        // 4 ordered, 1 canceled, 1 refunded, 2 shipped on the parent: nothing is left to reserve
        self::assertSame(-0.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-red'));
    }

    private function managing(array $types): object
    {
        return new class($types) {
            public function __construct(private readonly array $types)
            {
            }

            public function execute(string $type): bool
            {
                return in_array($type, $this->types, true);
            }
        };
    }

    /**
     * The service over a store whose source-item management rule is the given object's `execute(string $type): bool`; every type managed without one.
     */
    private function service(?object $managed = null): RemainingInconsistency
    {
        $allowed = $this->createMock(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $allowed->method('execute')->willReturnCallback(
            static fn (string $type): bool => $managed === null || $managed->execute($type)
        );
        $configuration = $this->createMock(GetStockItemConfigurationInterface::class);
        $configuration->expects(self::never())->method('execute');
        return new RemainingInconsistency($this->createMock(ResourceConnection::class), $allowed, $configuration);
    }

    private function item(string $sku, string $type, float $ordered, float $canceled, float $refunded, float $shipped, float $invoiced, bool $virtual): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()
            ->onlyMethods(['getSku', 'getProductType', 'getQtyOrdered', 'getQtyCanceled', 'getQtyRefunded', 'getQtyShipped', 'getQtyInvoiced', 'getIsVirtual'])
            ->getMock();
        $item->method('getSku')->willReturn($sku);
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($ordered);
        $item->method('getQtyCanceled')->willReturn($canceled);
        $item->method('getQtyRefunded')->willReturn($refunded);
        $item->method('getQtyShipped')->willReturn($shipped);
        $item->method('getQtyInvoiced')->willReturn($invoiced);
        $item->method('getIsVirtual')->willReturn($virtual);
        return $item;
    }

    /** @param Item[] $items */
    private function order(string $state, array $items): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getState', 'getItems'])->getMock();
        $order->method('getState')->willReturn($state);
        $order->method('getItems')->willReturn($items);
        return $order;
    }
}
