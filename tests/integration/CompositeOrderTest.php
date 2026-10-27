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

/**
 * A configurable's child carries no progress of its own: Magento books the
 * shipment, the cancellation and the refund on the parent line. The
 * compensation reads the child the way Magento's own calculator does, by
 * projecting the parent's progress, so a stale compensation after a partial
 * shipment is held against what really remains.
 */
class CompositeOrderTest extends StoreCase
{
    public function test_a_partly_shipped_configurable_is_read_through_its_parent(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeConfigurableOrder(2.0)->getEntityId();

        // registered at placement: the child's two units reserved
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertSame(-2.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));

        // one of the two shipped through the parent line, as a checkout order ships
        $this->invoice($orderId);
        $this->ship($orderId, $this->websiteSources()[0], [$this->parentItemId($orderId) => 1.0]);
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU), 'the shipment compensated one unit');

        $remaining = self::$objectManager->get(RemainingInconsistency::class)
            ->forOrderSku($this->order($orderId), self::CHILD_SKU, $stock);
        self::assertSame(-1.0, $remaining['expected'], 'one unit still to ship on the child, read off the parent');
        self::assertSame(0.0, $remaining['remaining'], 'the rows on record already say so');

        // a stale compensation for the unit already shipped: refused as balanced, nothing written
        $rows = count($this->reservationRows($orderId, self::CHILD_SKU));
        $this->appendExpectingBalanced($orderId, $stock, -1.0, self::CHILD_SKU);
        self::assertCount($rows, $this->reservationRows($orderId, self::CHILD_SKU), 'already balanced: nothing written');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));
    }
}
