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

/**
 * Rows of one order in different states, handled one by one: a refused
 * sku stays discoverable in Magento's listing and repairable by a
 * compensation while another sku of the order is still the core's to
 * register, and the pending one is never compensated by hand.
 */
class MixedStateTest extends StoreCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireInventory();
        if (!class_exists(GetSalableQuantityInconsistencies::class)) {
            self::markTestSkipped('Magento_InventoryReservationCli is not installed');
        }
    }

    public function test_a_refused_sku_is_listed_and_repaired_while_another_sku_is_still_owed(): void
    {
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $incrementId = (string) $this->order($orderId)->getIncrementId();
        // one sku refused for a repair; the other owed in a mode no handler takes, so its registration cannot run
        $this->insertOwed($orderId, 1.0, self::SKU);
        $this->refuseRow($orderId, self::SKU, 'simulated: a discrepancy only a compensation repairs');
        $this->insertHeld($orderId, 1.0, self::CHILD_SKU);

        // the listing names the refused sku and leaves the owed one to the core
        $listed = $this->listedItems();
        self::assertArrayHasKey($incrementId, $listed, 'the order is listed for its refused sku');
        self::assertSame([self::SKU], array_keys($listed[$incrementId]), 'the owed sku is not an inconsistency to compensate');

        // the refused sku is judged and settled without the other row's registration
        $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('compensation', $this->obligations($orderId)[0]->settledBy);
        self::assertSame('owed', $this->obligationStatus($orderId, self::CHILD_SKU), 'the owed row untouched');
        self::assertSame(0, $this->obligationAttempts($orderId, self::CHILD_SKU), 'its registration was not attempted for another row\'s repair');
        self::assertArrayNotHasKey($incrementId, $this->listedItems(), 'nothing left to compensate; the owed sku stays the core\'s');

        // the owed sku's compensation has the registration attempted first, which fails: refused, nothing written
        try {
            $this->append([$this->manualCompensation($orderId, $stock, -1.0, self::CHILD_SKU)]);
            self::fail('a pending registration that fails must refuse the compensation');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('was not written', $e->getMessage());
            self::assertStringContainsString('No stock registration handler for mode "held"', $e->getMessage());
        }
        self::assertSame(0.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU), 'nothing written for the owed sku');
        self::assertSame('owed', $this->obligationStatus($orderId, self::CHILD_SKU));

        // released, the core registers it, and the order is complete
        $this->releaseRegistration($orderId);
        self::assertTrue($this->register($orderId));
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertSame(-1.0, $this->reservationSum($orderId, $stock, self::CHILD_SKU));
        self::assertNotContains($orderId, $this->orderIdsRefused());
        self::assertFalse($this->register($orderId), 'nothing left owed');
    }

    public function test_a_compensation_for_a_registrable_owed_sku_has_the_registration_complete_it_first(): void
    {
        $stock = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0, reserveStock: false)->getEntityId();
        $this->insertOwed($orderId, 1.0);

        // the registration places the unit first; the compensation then finds the sku balanced
        $this->appendExpectingBalanced($orderId, $stock, -1.0);
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('registration', $this->obligations($orderId)[0]->settledBy);
        self::assertSame(['order_placed:' . $stock . ':-1.0'], $this->reservationEvents($orderId));
    }

    /**
     * Magento's inconsistency listing as the operator sees it: the increment ids and their items.
     *
     * @return array<string, array<string, float>>
     */
    private function listedItems(): array
    {
        $listing = self::$objectManager->get(GetSalableQuantityInconsistencies::class);
        $items = [];
        foreach ($listing->execute() as $page) {
            foreach ((array) $page as $inconsistency) {
                $items[(string) $inconsistency->getOrderIncrementId()] = $inconsistency->getItems();
            }
        }
        return $items;
    }
}
