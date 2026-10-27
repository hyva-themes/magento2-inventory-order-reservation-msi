<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * One transaction covers a compensation's row and the settlement of the
 * sku it balances: a failure after the row is written leaves no row and
 * the sku's discrepancy on record; the next attempt writes and settles.
 */
class CompensationTransactionTest extends StoreCase
{
    /** Fails every update of the obligation table while it exists: the settlement, after the row went in. */
    private const FAILING_TRIGGER = 'hyva_test_settlement_fails';

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

    /**
     * From here on every update of the obligation table fails inside the
     * database with a signaled error, the way a lost connection or a
     * deadlock would fail the settlement after the row is in.
     */
    private function failSettlements(): void
    {
        $this->letSettlementsPass();
        $this->connection()->query(sprintf(
            "CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated failure after the compensation write'",
            self::FAILING_TRIGGER,
            $this->table(ObligationResource::TABLE)
        ));
    }

    /**
     * The trigger gone; a transaction a failure left open is rolled back
     * first, since the store refuses DDL inside one.
     */
    private function letSettlementsPass(): void
    {
        $connection = $this->connection();
        while ($connection->getTransactionLevel() > 0) {
            $connection->rollBack();
        }
        $connection->query(sprintf('DROP TRIGGER IF EXISTS %s', self::FAILING_TRIGGER));
    }
}
