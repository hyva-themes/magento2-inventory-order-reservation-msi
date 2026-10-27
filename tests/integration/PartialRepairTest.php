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
 * A compensation settles the sku it balances and nothing else: an order
 * the registration gave up on with two skus stays unresolved until each
 * sku has been repaired; and a registration retried after a partial
 * repair places what is still missing, never the whole order again; a
 * surplus is one row's refusal, the order's other rows placed in the run.
 */
class PartialRepairTest extends StoreCase
{
    public function test_a_compensation_settles_one_sku_of_a_refused_order_at_a_time(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0, self::SKU);
        $this->insertOwed($orderId, 1.0, self::CHILD_SKU);
        $this->refuse($orderId);
        self::assertContains($orderId, $this->orderIdsRefused(), 'given up: listed as unresolved');

        // the first sku repaired: its row settles, the other keeps its discrepancy
        $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU), 'the other sku keeps its discrepancy');
        self::assertContains($orderId, $this->orderIdsRefused(), 'the order stays listed');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::SKU));
        self::assertSame(0.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));

        // the first sku again: balanced, nothing written, no state change
        $rows = count($this->reservationRows($orderId, self::SKU));
        $this->appendExpectingBalanced($orderId, $stock, -1.0, self::SKU);
        self::assertCount($rows, $this->reservationRows($orderId, self::SKU), 'no reservation row added');
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU));

        // the second sku repaired: the order leaves the unresolved list
        $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::CHILD_SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertNotContains($orderId, $this->orderIdsRefused(), 'every sku settled: no longer listed');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));
    }

    public function test_a_retried_registration_places_only_what_a_partial_compensation_left(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(3.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 3.0);
        $this->refuse($orderId);

        // one unit repaired by hand: partial, two still missing, the sku keeps its discrepancy
        $this->append([$this->manualCompensation($orderId, $stock, -1.0)]);
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock));
        self::assertSame('refused', $this->obligationStatus($orderId));

        // the retry the listing suggests: the registration places the two still missing, not the three ordered
        $run = $this->runCommand('inventory:order-reservation:run', [(string) $orderId]);
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString(sprintf('order %d: registered', $orderId), $run['out']);
        self::assertSame(-3.0, $this->reservationSum($orderId, $stock), 'three ordered, three reserved');
        self::assertSame(['manual_compensation:' . $stock . ':-1.0', 'order_placed:' . $stock . ':-2.0'], $this->reservationEvents($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('registration', $this->obligations($orderId)[0]->settledBy);
        self::assertFalse($this->register($orderId), 'nothing left owed');
    }

    public function test_a_sku_reserved_past_what_the_order_owes_is_given_up_on_at_once_and_a_compensation_repairs_it(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(3.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 3.0);
        // a stray placement of four for the three ordered, written before the registration ran
        $this->append([$this->reservationRow($orderId, $stock, -4.0, self::SKU, 'order_placed')]);
        self::assertSame(-4.0, $this->reservationSum($orderId, $stock));

        // nothing a placement can fix: the row is refused with the reason, no attempt counted, nothing written
        self::assertTrue($this->register($orderId), 'the run completed; the refusal is its result');
        self::assertSame(-4.0, $this->reservationSum($orderId, $stock), 'nothing written');
        self::assertSame('refused', $this->obligationStatus($orderId), 'refused at once, not after the attempt limit');
        self::assertSame(0, $this->obligationAttempts($orderId), 'a result, not a failure');
        self::assertStringContainsString('reserved +1.0000 past', (string) $this->obligations($orderId)[0]->lastError);
        self::assertContains($orderId, $this->orderIdsRefused(), 'listed for repair');

        // the compensation the reason names: applied, the sku settled, the order off the list
        $this->append([$this->manualCompensation($orderId, $stock, 1.0)]);
        self::assertSame(-3.0, $this->reservationSum($orderId, $stock), 'three ordered, three reserved');
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('compensation', $this->obligations($orderId)[0]->settledBy);
        self::assertNotContains($orderId, $this->orderIdsRefused());
    }
}
