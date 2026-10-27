<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Magento\InventoryReservationCli\Model\GetSalableQuantityInconsistencies;
use Magento\InventoryReservations\Model\ResourceModel\SaveMultiple;
use Magento\InventoryReservationsApi\Model\CleanupReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;

/**
 * The sequence a store goes through while this package is missing: an
 * order is accepted and its registration stays owed, the order ships,
 * the merchant compensates the discrepancy by hand with Magento's command
 * (which, without this package, writes the row as it is), Magento's
 * cleanup removes the balanced rows, and the package is installed. The
 * first registration then completes the order without a second
 * placement: nothing remains by Magento's rule, so the row is balanced.
 */
class MissingCompanionRecoveryTest extends StoreCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireInventory();
        if (!class_exists(GetSalableQuantityInconsistencies::class)) {
            self::markTestSkipped('Magento_InventoryReservationCli is not installed');
        }
    }

    public function test_an_order_shipped_and_compensated_by_hand_while_the_package_was_missing_recovers_without_a_second_placement(): void
    {
        $stock = $this->websiteStockId();
        $source = $this->websiteSources()[0];
        $sourceBefore = $this->sourceQty($source);
        $order = $this->placeOrder(1.0, reserveStock: false);
        $orderId = (int) $order->getEntityId();
        // the package missing: the registration cannot run, the obligation stays owed through the saves
        $this->insertHeld($orderId, 1.0);

        $this->ship($orderId, $source);
        self::assertSame('owed', $this->obligationStatus($orderId), 'still the module\'s, the package missing');
        // the shipment's row with no placement to balance it: the discrepancy Magento's listing shows a
        // merchant without this package (with it installed, its filter keeps an owed sku out of the listing)
        self::assertSame(1.0, $this->reservationSum($orderId, $stock));

        // the merchant compensates by hand; without the package Magento's command writes the row as it is
        $builder = self::$objectManager->get(ReservationBuilderInterface::class);
        $row = $builder->setSku(self::SKU)->setStockId($stock)->setQuantity(-1.0)->setMetadata(json_encode([
            'event_type' => 'manual_compensation',
            'object_type' => 'order',
            'object_id' => (string) $orderId,
            'object_increment_id' => (string) $order->getIncrementId(),
        ]))->build();
        self::$objectManager->get(SaveMultiple::class)->execute([$row]);
        self::assertSame(0.0, $this->reservationSum($orderId, $stock), 'balanced by hand');

        // Magento's own cleanup removes the balanced rows
        self::$objectManager->get(CleanupReservationsInterface::class)->execute();
        self::assertSame([], $this->reservationRows($orderId), 'the order\'s rows are gone');
        self::assertSame('owed', $this->obligationStatus($orderId), 'the module\'s record outlives Magento\'s rows');

        // the package installed: the first registration completes the order with what remains, which is nothing
        $this->releaseRegistration($orderId);
        self::assertTrue($this->register($orderId));
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('balanced', $this->obligations($orderId)[0]->settledBy);
        self::assertSame([], $this->reservationRows($orderId), 'no second placement');
        self::assertSame($sourceBefore - 1.0, $this->sourceQty($source), 'the shipment\'s deduction alone: the salable quantity is right');
        self::assertNotContains((string) $order->getIncrementId(), $this->listedOrders(), 'consistent');
        self::assertFalse($this->register($orderId), 'nothing left owed');
    }

    /**
     * The increment ids Magento's inconsistency listing names.
     *
     * @return string[]
     */
    private function listedOrders(): array
    {
        $ids = [];
        foreach (self::$objectManager->get(GetSalableQuantityInconsistencies::class)->execute() as $page) {
            foreach ((array) $page as $inconsistency) {
                $ids[] = (string) $inconsistency->getOrderIncrementId();
            }
        }
        return $ids;
    }
}
