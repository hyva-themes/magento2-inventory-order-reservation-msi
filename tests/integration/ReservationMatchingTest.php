<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The reservation table read as the service's SQL reads it: a row naming
 * an order entity id counts for that order only, a row naming only an
 * order number counts while a single order carries it and is ambiguous
 * otherwise, and a row on a stock deleted since still counts.
 */
class ReservationMatchingTest extends StoreCase
{
    public function test_two_orders_sharing_an_increment_id_each_count_their_own_rows(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $first = $this->placeOrder(2.0);
        $second = $this->placeOrder(3.0);
        $secondIncrementId = (string) $second->getIncrementId();
        // Magento keeps an increment id unique per store: the second order moves to the admin store to carry the first's
        $this->moveOrder((int) $second->getEntityId(), (string) $first->getIncrementId(), 0);
        try {
            $second = $this->order((int) $second->getEntityId());

            self::assertSame(-2.0, $this->service()->forOrderSku($first, self::SKU, $stock)['actual'], 'the first order\'s placement only');
            self::assertSame(-3.0, $this->service()->forOrderSku($second, self::SKU, $stock)['actual'], 'the second order\'s placement only');
            self::assertFalse($this->service()->hasAmbiguousRows($first, self::SKU), 'every row names an entity id');

            $this->insertRow($stock, -1.0, ['event_type' => 'order_placed', 'object_type' => 'order', 'object_increment_id' => (string) $first->getIncrementId()]);

            self::assertTrue($this->service()->hasAmbiguousRows($first, self::SKU));
            self::assertTrue($this->service()->hasAmbiguousRows($second, self::SKU));
            self::assertSame(-2.0, $this->service()->forOrderSku($first, self::SKU, $stock)['actual'], 'the row naming only the number counts for neither');
            self::assertSame(-3.0, $this->service()->forOrderSku($second, self::SKU, $stock)['actual']);
        } finally {
            $this->moveOrder((int) $second->getEntityId(), $secondIncrementId, 1);
        }
    }

    public function test_a_row_naming_no_entity_id_counts_by_the_increment_id_while_one_order_carries_it(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $order = $this->placeOrder(2.0);
        $incrementId = (string) $order->getIncrementId();
        // every way a row can name no entity id: absent, null, empty, zero as a string and as a number
        $metadata = ['event_type' => 'shipment_created', 'object_type' => 'order', 'object_increment_id' => $incrementId];
        $this->insertRow($stock, 0.25, $metadata);
        $this->insertRow($stock, 0.25, $metadata + ['object_id' => null]);
        $this->insertRow($stock, 0.25, $metadata + ['object_id' => '']);
        $this->insertRow($stock, 0.25, $metadata + ['object_id' => '0']);
        $this->insertRow($stock, 1.0, $metadata + ['object_id' => 0]);
        // another order's row whose number starts with this one's
        $this->insertRow($stock, 5.0, ['event_type' => 'shipment_created', 'object_type' => 'order', 'object_increment_id' => $incrementId . '0']);

        self::assertFalse($this->service()->hasAmbiguousRows($order, self::SKU));
        self::assertSame(0.0, $this->service()->forOrderSku($order, self::SKU, $stock)['actual'], 'the placement and the five rows naming only its number');
    }

    public function test_a_row_on_a_stock_missing_from_inventory_stock_is_counted_for_the_row(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $order = $this->placeOrder(1.0);
        $connection = $this->connection();
        $deleted = (int) $connection->fetchOne($connection->select()->from($this->table('inventory_stock'), ['MAX(stock_id)'])) + 100;
        $this->insertRow($deleted, -1.0, ['event_type' => 'shipment_created', 'object_type' => 'order', 'object_id' => (string) $order->getEntityId()]);

        $remainders = $this->service()->remaindersForRow($order, self::SKU, $stock, $this->productId(self::SKU));

        self::assertArrayHasKey($deleted, $remainders, 'the stock read off the reservation index, not off inventory_stock');
        self::assertSame(1.0, $remainders[$deleted], 'nothing is expected there, so the row is a surplus');
        self::assertSame(0.0, $remainders[$stock]);
    }

    private function service(): RemainingInconsistency
    {
        return self::$objectManager->create(RemainingInconsistency::class);
    }

    private function insertRow(int $stockId, float $qty, array $metadata): void
    {
        $this->connection()->insert($this->table('inventory_reservation'), [
            'stock_id' => $stockId,
            'sku' => self::SKU,
            'quantity' => $qty,
            'metadata' => json_encode($metadata),
        ]);
    }

    private function moveOrder(int $orderId, string $incrementId, int $storeId): void
    {
        $this->connection()->update(
            $this->table('sales_order'),
            ['increment_id' => $incrementId, 'store_id' => $storeId],
            ['entity_id = ?' => $orderId]
        );
    }
}
