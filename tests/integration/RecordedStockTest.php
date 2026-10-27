<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;

/**
 * The placement the handler writes is the one Magento's own service
 * writes but for the stock it names: one row per sku on the stock the
 * order was recorded on, with Magento's metadata, through Inventory's
 * writer.
 */
class RecordedStockTest extends StoreCase
{
    public function test_the_placement_carries_magentos_metadata_on_the_recorded_stock(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $order = $this->placeOrder(2.0, reserveStock: true);
        $orderId = (int) $order->getEntityId();

        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('registration', $this->obligations($orderId)[0]->settledBy);
        $rows = $this->reservationRows($orderId);
        self::assertCount(1, $rows);
        self::assertSame($stock, (int) $rows[0]['stock_id'], 'the stock the obligation carries');
        self::assertSame(-2.0, (float) $rows[0]['quantity']);
        self::assertSame(
            ['event_type' => 'order_placed', 'object_type' => 'order', 'object_id' => (string) $orderId, 'object_increment_id' => (string) $order->getIncrementId()],
            json_decode((string) $rows[0]['metadata'], true),
            'the metadata Magento writes for a placement, so its cleanup and listing read the row as their own'
        );
    }
}
