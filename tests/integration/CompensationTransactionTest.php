<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * One transaction covers a compensation's row and the settlement of the
 * sku it balances: a failure after the row is written leaves no row and
 * the sku's discrepancy on record; the next attempt writes and settles.
 */
class CompensationTransactionTest extends StoreCase
{
    public function test_a_failure_after_the_compensation_write_rolls_the_write_back_and_the_next_attempt_settles(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);
        $this->refuse($orderId);
        self::assertSame([], $this->reservationRows($orderId), 'given up: no reservation row');

        // the settlement fails inside the database, after the compensation row went in
        $this->failSettlements();
        try {
            $this->append([$this->manualCompensation($orderId, $stock, -1.0)]);
            self::fail('the failed settlement must surface');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('was not written', $e->getMessage());
            self::assertStringNotContainsString('simulated failure', $e->getMessage(), 'the database text stays out of the caller\'s message');
            self::assertStringContainsString('simulated failure after the compensation write', (string) $e->getPrevious()?->getMessage());
        } finally {
            $this->letSettlementsPass();
        }
        self::assertSame([], $this->reservationRows($orderId), 'the compensation row rolled back with the failure');
        self::assertSame(0.0, $this->reservationSum($orderId, $stock));
        self::assertSame('refused', $this->obligationStatus($orderId), 'the sku keeps its discrepancy');
        self::assertContains($orderId, $this->orderIdsRefused(), 'the order stays listed');

        // the next attempt: the row and the settlement land together
        $this->append([$this->manualCompensation($orderId, $stock, -1.0)]);
        self::assertSame(['manual_compensation:' . $stock . ':-1.0'], $this->reservationEvents($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('compensation', $this->obligations($orderId)[0]->settledBy);
        self::assertNotContains($orderId, $this->orderIdsRefused(), 'settled: no longer listed');
    }
}
