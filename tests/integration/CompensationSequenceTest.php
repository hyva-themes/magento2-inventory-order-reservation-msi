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
use Magento\InventoryReservationCli\Model\GetSalableQuantityInconsistencies;
use Magento\InventoryReservationsApi\Model\CleanupReservationsInterface;

/**
 * Magento's repair path against a registered order: a stale request
 * writes nothing, a partial one is applied and the rest reported, the
 * cleanup of balanced rows changes nothing about what the module knows,
 * and an owed order is left out of the inconsistency listing.
 */
class CompensationSequenceTest extends StoreCase
{
    public function test_a_stale_compensation_after_the_shipment_writes_nothing(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0)->getEntityId();
        $this->ship($orderId, $this->websiteSources()[0]);
        self::assertSame(0.0, $this->reservationSum($orderId, $stock));
        $rows = count($this->reservationRows($orderId));

        $this->appendExpectingBalanced($orderId, $stock, -1.0);

        self::assertCount($rows, $this->reservationRows($orderId), 'already balanced: nothing written');
        self::assertSame(0.0, $this->reservationSum($orderId, $stock));
    }

    public function test_a_lost_reservation_is_compensated_in_parts_and_never_past_balance(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(2.0)->getEntityId();
        // the inconsistency Magento's listing finds: the placement row gone
        $this->dropPlacements();
        self::assertSame(0.0, $this->reservationSum($orderId, $stock));

        // -2 remains: more than that is refused with nothing written
        try {
            $this->append([$this->manualCompensation($orderId, $stock, -3.0)]);
            self::fail('more than remains must be refused');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('more than remains', $e->getMessage());
        }
        self::assertSame(0.0, $this->reservationSum($orderId, $stock), 'the refused request wrote nothing');

        // the wrong direction is refused too
        try {
            $this->append([$this->manualCompensation($orderId, $stock, 1.0)]);
            self::fail('the wrong direction must be refused');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('wrong direction', $e->getMessage());
        }

        $this->append([$this->manualCompensation($orderId, $stock, -1.0)]);
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock), 'the first part is written');

        $this->append([$this->manualCompensation($orderId, $stock, -1.0)]);
        self::assertSame(-2.0, $this->reservationSum($orderId, $stock), 'the second part balances the order');

        // balanced: a further request of any size is refused as "already balanced", nothing written
        $this->appendExpectingBalanced($orderId, $stock, -1.0);
        $this->appendExpectingBalanced($orderId, $stock, -3.0);
        self::assertSame(-2.0, $this->reservationSum($orderId, $stock), 'balanced: further requests write nothing');
        self::assertSame(['manual_compensation:' . $stock . ':-1.0', 'manual_compensation:' . $stock . ':-1.0'], $this->reservationEvents($orderId));
    }

    public function test_magento_s_cleanup_of_balanced_rows_leaves_the_order_known_and_a_later_request_balanced(): void
    {
        $this->requireInventory();
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0)->getEntityId();
        $this->ship($orderId, $this->websiteSources()[0]);
        self::assertCount(2, $this->reservationRows($orderId));

        self::$objectManager->get(CleanupReservationsInterface::class)->execute();

        self::assertSame([], $this->reservationRows($orderId), 'Magento deleted the balanced rows');
        self::assertSame('done', $this->obligationStatus($orderId), 'the obligation outlives the rows');
        $this->appendExpectingBalanced($orderId, $stock, -1.0);
        self::assertSame([], $this->reservationRows($orderId), 'a complete order with no rows is balanced: nothing written');
    }

    public function test_an_owed_order_is_left_out_of_the_inconsistency_listing_until_registered(): void
    {
        $this->requireInventory();
        $class = GetSalableQuantityInconsistencies::class;
        if (!class_exists($class)) {
            self::markTestSkipped('Magento_InventoryReservationCli is not installed');
        }
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);
        $incrementId = (string) $this->order($orderId)->getIncrementId();

        self::assertNotContains($incrementId, $this->listedInconsistencies(), 'owed: not Magento\'s to repair');

        self::assertTrue($this->register($orderId));
        self::assertNotContains($incrementId, $this->listedInconsistencies(), 'registered: consistent');
    }

    /** @return string[] the increment ids Magento's listing names */
    private function listedInconsistencies(): array
    {
        $listing = self::$objectManager->get(GetSalableQuantityInconsistencies::class);
        $ids = [];
        foreach ($listing->execute() as $page) {
            foreach ((array) $page as $inconsistency) {
                $ids[] = (string) $inconsistency->getOrderIncrementId();
            }
        }
        return $ids;
    }
}
