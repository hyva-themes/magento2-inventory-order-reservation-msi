<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Compensation;

use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Rename;
use Hyva\InventoryOrderReservationMsi\Test\Unit\ReservationStore;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ProductIdentities;
use Hyva\InventoryOrderReservation\Model\ResourceModel\WebsiteStock;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RemainingInconsistencyTest extends TestCase
{
    use ReservationStore;

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

    public function test_the_expectation_counts_on_the_stock_the_website_sells_from_now_and_is_zero_elsewhere(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('mug', 'simple', 2, 0, 0, 0, 0, false)]);
        $service = $this->service();
        self::assertSame(-2.0, $service->expectedSum($order, 'mug', 2), 'the website sells from stock 2');
        self::assertSame(0.0, $service->expectedSum($order, 'mug', 1), 'another stock: Magento counts nothing there');
        self::assertSame(-2.0, $service->expectedSum($order, 'mug'), 'no stock named: the order\'s expectation itself');
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
        $child = $this->item('shirt-blue', 'simple', 2.0, 0.0, 0.0, 0.0, 0.0, false, parent: $parent);
        $service = $this->service($this->managing(['simple']));

        self::assertSame(-1.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-blue'));
    }

    #[Test]
    public function a_child_of_a_bundle_that_ships_separately_keeps_its_own_progress(): void
    {
        $parent = $this->item('bundle', 'bundle', 1.0, 0.0, 0.0, 1.0, 1.0, false, options: [
            'shipment_type' => \Magento\Catalog\Model\Product\Type\AbstractType::SHIPMENT_SEPARATELY,
        ]);
        $child = $this->item('bundle-part', 'simple', 3.0, 0.0, 0.0, 1.0, 3.0, false, parent: $parent);
        $service = $this->service($this->managing(['simple']));

        self::assertSame(-2.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'bundle-part'));
    }

    #[Test]
    public function a_canceled_and_refunded_parent_projects_both_onto_its_child(): void
    {
        $parent = $this->item('shirt-red', 'configurable', 4.0, 1.0, 1.0, 2.0, 3.0, false);
        $child = $this->item('shirt-red', 'simple', 4.0, 0.0, 0.0, 0.0, 0.0, false, parent: $parent);
        $service = $this->service($this->managing(['simple']));

        // 4 ordered, 1 canceled, 1 refunded, 2 shipped on the parent: nothing is left to reserve
        self::assertSame(-0.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-red'));
    }

    #[Test]
    public function a_line_with_custom_options_counts_under_its_products_sku(): void
    {
        // the line's sku carries the option's suffix; the reservation and the record name the product's
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('SHIRT-engr', 'simple', 2, 0, 0, 0, 0, false, productId: 10)]);
        $service = $this->service($this->managing(['simple']), [10 => ['sku' => 'SHIRT', 'type' => 'simple']]);

        self::assertSame(-2.0, $service->expectedSum($order, 'SHIRT'));
        self::assertSame(0.0, $service->expectedSum($order, 'SHIRT-engr'), 'no stock is kept under the line\'s own sku');
    }

    #[Test]
    public function a_row_finds_its_products_lines_after_the_catalog_renamed_the_product(): void
    {
        // recorded as OLD for product 10; the catalog calls it NEW now
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('OLD', 'simple', 2, 0, 0, 0, 0, false, productId: 10)]);
        $service = $this->service($this->managing(['simple']), [10 => ['sku' => 'NEW', 'type' => 'simple']]);

        self::assertSame(-2.0, $service->expectedSum($order, 'OLD', null, 10), 'the row\'s product still owes two');
        self::assertSame(0.0, $service->expectedSum($order, 'OLD'), 'by sku alone the renamed line is not found');
    }

    #[Test]
    public function a_row_is_renamed_when_the_catalog_names_its_product_otherwise(): void
    {
        $service = $this->service(null, [10 => ['sku' => 'NEW', 'type' => 'simple'], 11 => ['sku' => 'mug', 'type' => 'simple']]);

        self::assertSame('NEW', $service->renamedTo(new Obligation(9, 'OLD', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10)));
        self::assertNull($service->renamedTo(new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 11)), 'same sku');
        self::assertNull($service->renamedTo(new Obligation(9, 'gone', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 12)), 'product deleted');
        self::assertNull($service->renamedTo(new Obligation(9, 'old-row', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY)), 'no product id recorded');
    }

    #[Test]
    public function a_renamed_row_follows_its_reservations_to_the_new_sku_once_magento_moved_them(): void
    {
        // the consumer moved the order's placement from OLD to NEW
        $this->reserve(2, 'NEW', -2.0, 9);
        $row = new Obligation(9, 'OLD', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);

        $rename = $this->remainingOver([10 => ['sku' => 'NEW', 'type' => 'simple']])->rename($this->openOrder(9, '000000009', []), $row);

        self::assertSame(['OLD', 'NEW', Rename::FOLLOWED], [$rename->recordedSku, $rename->currentSku, $rename->state]);
    }

    #[Test]
    public function a_renamed_row_is_pending_while_a_reservation_of_the_order_remains_under_the_recorded_sku(): void
    {
        $catalog = [10 => ['sku' => 'NEW', 'type' => 'simple']];
        $row = new Obligation(9, 'OLD', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);
        $this->reserve(2, 'OLD', -2.0, 9);
        $this->reserve(2, 'NEW', -1.0, 7);

        self::assertSame(Rename::PENDING, $this->remainingOver($catalog)->rename($this->openOrder(9, '000000009', []), $row)->state, 'the order\'s own row');

        $this->reservations = [];
        $this->reserve(2, 'OLD', -2.0, null, '000000009');
        self::assertSame(
            Rename::PENDING,
            $this->remainingOver($catalog, ordersByIncrementId: ['000000009' => 2])->rename($this->openOrder(9, '000000009', []), $row)->state,
            'a row naming only the order number, which another order carries too'
        );

        $this->reservations = [];
        $this->reserve(2, 'OLD', -2.0, 8);
        self::assertSame(Rename::FOLLOWED, $this->remainingOver($catalog)->rename($this->openOrder(9, '000000009', []), $row)->state, 'another order\'s row');
    }

    #[Test]
    public function a_renamed_row_is_not_followed_with_synchronize_with_catalog_off(): void
    {
        $row = new Obligation(9, 'OLD', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);

        $rename = $this->remainingOver([10 => ['sku' => 'NEW', 'type' => 'simple']], synchronized: false)
            ->rename($this->openOrder(9, '000000009', []), $row);

        self::assertSame(Rename::NOT_SYNCHRONIZED, $rename->state);
    }

    #[Test]
    public function a_row_the_catalog_did_not_rename_has_no_rename(): void
    {
        $row = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);

        self::assertNull($this->remainingOver([10 => ['sku' => 'mug', 'type' => 'simple']])->rename($this->openOrder(9, '000000009', []), $row));
    }

    #[Test]
    public function within_a_memoized_call_a_rows_repeated_reads_run_once(): void
    {
        // rows on stocks 1 and 3; the website sells from stock 2: three stocks to read
        $this->reserve(1, 'mug', 1.0, 9);
        $this->reserve(3, 'mug', -1.0, 9);
        $service = $this->remainingOver([10 => ['sku' => 'mug', 'type' => 'simple']]);
        $order = $this->openOrder(9, '000000009', [[10, 'mug', 2.0]]);
        $row = new Obligation(9, 'mug', 'simple', 2.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);

        $remainders = $service->memoized(static function () use ($service, $order, $row): array {
            self::assertNull($service->rename($order, $row));
            self::assertFalse($service->hasAmbiguousRows($order, 'mug'));
            return $service->remaindersForRow($order, 'mug', 2, 10);
        });

        self::assertSame([1 => -1.0, 2 => -2.0, 3 => 1.0], $remainders);
        self::assertSame(1, $this->queries['orders by increment id'], 'the order number\'s uniqueness');
        self::assertSame(1, $this->queries['stocks with rows'], 'the stocks holding reservations');
        self::assertSame(1, $this->queries['products'], 'the catalog\'s identities');
        self::assertSame(3, $this->queries['sum'], 'one sum per stock');
    }

    #[Test]
    public function the_memo_ends_with_its_call(): void
    {
        $this->reserve(2, 'mug', -2.0, 9);
        $service = $this->remainingOver([10 => ['sku' => 'mug', 'type' => 'simple']]);
        $order = $this->openOrder(9, '000000009', [[10, 'mug', 2.0]]);

        $service->memoized(static fn (): array => $service->remaindersForRow($order, 'mug', 2, 10));
        $service->memoized(static fn (): array => $service->remaindersForRow($order, 'mug', 2, 10));
        $service->remaindersForRow($order, 'mug', 2, 10);

        self::assertSame(3, $this->queries['stocks with rows'], 'read again by each call');
        self::assertSame(1 + 1 + 2, $this->queries['orders by increment id'], 'once per memoized call, per query outside one');
    }

    #[Test]
    public function a_row_with_a_product_id_ignores_a_composite_parent_line(): void
    {
        $parent = $this->item('shirt-blue', 'configurable', 2.0, 0.0, 0.0, 0.0, 0.0, false, productId: 20);
        $child = $this->item('shirt-blue', 'simple', 2.0, 0.0, 0.0, 0.0, 0.0, false, productId: 21, parent: $parent);
        $service = $this->service(null, [20 => ['sku' => 'shirt', 'type' => 'configurable'], 21 => ['sku' => 'shirt-blue', 'type' => 'simple']]);

        self::assertSame(-2.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-blue', null, 21));
    }

    #[Test]
    public function a_grouped_products_child_counts_under_its_own_product(): void
    {
        // Magento saves a grouped product's child line with the type "grouped"
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('chair', 'grouped', 3, 0, 0, 1, 0, false, productId: 11)]);
        $service = $this->service($this->managing(['simple']), [11 => ['sku' => 'chair', 'type' => 'simple']]);

        self::assertSame(-2.0, $service->expectedSum($order, 'chair'));
    }

    #[Test]
    public function a_grouped_line_whose_product_is_gone_counts_as_magentos_listing_counts_it(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, [$this->item('chair', 'grouped', 3, 0, 0, 0, 0, false, productId: 12)]);

        self::assertSame(-3.0, $this->service($this->managing(['simple']))->expectedSum($order, 'chair'));
    }

    #[Test]
    public function a_composite_parent_line_is_left_to_its_child_by_the_catalogs_type(): void
    {
        // a configurable parent line carries its child's sku; the catalog names its product configurable
        $parent = $this->item('shirt-blue', 'configurable', 2.0, 0.0, 0.0, 0.0, 0.0, false, productId: 20);
        $child = $this->item('shirt-blue', 'simple', 2.0, 0.0, 0.0, 0.0, 0.0, false, productId: 21);
        $service = $this->service(null, [20 => ['sku' => 'shirt', 'type' => 'configurable'], 21 => ['sku' => 'shirt-blue', 'type' => 'simple']]);

        self::assertSame(-2.0, $service->expectedSum($this->order(Order::STATE_PROCESSING, [$parent, $child]), 'shirt-blue'));
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
    /**
     * @param array<int, array{sku: string, type: string}> $catalog what the catalog holds by product id
     */
    private function service(?object $managed = null, array $catalog = []): RemainingInconsistency
    {
        $allowed = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $allowed->method('execute')->willReturnCallback(
            static fn (string $type): bool => $managed === null || $managed->execute($type)
        );
        $managedItem = $this->createStub(StockItemConfigurationInterface::class);
        $managedItem->method('isManageStock')->willReturn(true);
        $configuration = $this->createStub(GetStockItemConfigurationInterface::class);
        $configuration->method('execute')->willReturn($managedItem);
        $websiteStock = $this->createMock(WebsiteStock::class);
        $websiteStock->method('idForWebsiteCode')->with('base')->willReturn(2);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $websites = $this->createMock(WebsiteRepositoryInterface::class);
        $websites->method('getById')->with(1)->willReturn($website);
        $products = $this->createStub(ProductIdentities::class);
        $products->method('byProductIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($catalog, array_flip($ids))
        );
        return new RemainingInconsistency(
            $this->createStub(ResourceConnection::class),
            $allowed,
            $configuration,
            $websiteStock,
            $websites,
            $products,
            $this->createStub(ScopeConfigInterface::class)
        );
    }

    private function item(
        string $sku,
        string $type,
        float $ordered,
        float $canceled,
        float $refunded,
        float $shipped,
        float $invoiced,
        bool $virtual,
        ?int $productId = null,
        ?Item $parent = null,
        array $options = []
    ): Item {
        $item = $this->createStub(Item::class);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getProductOptions')->willReturn($options);
        $item->method('getSku')->willReturn($sku);
        $item->method('getProductId')->willReturn($productId);
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
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->createStub(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getItems')->willReturn($items);
        $order->method('getStore')->willReturn($store);
        return $order;
    }
}
