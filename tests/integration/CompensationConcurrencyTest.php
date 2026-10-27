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
 * Two processes on one order: a compensation waits on the order's lock a
 * registration holds and finds its work committed, so nothing is written twice.
 */
class CompensationConcurrencyTest extends StoreCase
{
    public function test_a_compensation_waits_for_a_running_registration_and_then_writes_nothing(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        $child = $this->holdLockWhileRegistering($orderId, 4);
        $started = microtime(true);
        // the registration balanced the order while the compensation waited: refused as balanced
        $this->appendExpectingBalanced($orderId, $stock, -1.0);
        $waited = microtime(true) - $started;
        $this->finish($child);

        self::assertGreaterThan(2.0, $waited, 'the compensation waited on the lock the registration held');
        self::assertSame(['order_placed:' . $stock . ':-1.0'], $this->reservationEvents($orderId), 'one row, the registration\'s');
        self::assertSame('done', $this->obligationStatus($orderId));
    }
}
