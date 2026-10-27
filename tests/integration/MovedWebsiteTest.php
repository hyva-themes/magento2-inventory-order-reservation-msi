<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\InventoryReservationCli\Model\GetSalableQuantityInconsistencies;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\InventorySalesApi\Model\GetAssignedStockIdForWebsiteInterface;

/**
 * The website moved to another stock between the order's recording and
 * its registration. The writer repairs the recorded stock only and only
 * downward; whatever remains elsewhere is refused with the stocks named,
 * and a compensation on each stock named settles the row, so an order is
 * never completed on the mapping of the day with a discrepancy left
 * behind on the stock it was recorded on, or the one it moved to.
 */
class MovedWebsiteTest extends StoreCase
{
    private int $stockA = 0;
    private int $stockB = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireInventory();
        if (!class_exists(GetSalableQuantityInconsistencies::class)) {
            self::markTestSkipped('Magento_InventoryReservationCli is not installed');
        }
        $this->stockA = $this->websiteStockId();
        $this->stockB = $this->otherStockId();
    }

    protected function tearDown(): void
    {
        $this->moveWebsiteTo($this->stockA);
        parent::tearDown();
    }

    public function test_shipped_on_the_recorded_stock_before_the_move_the_placement_lands_there(): void
    {
        $orderId = $this->owedOrder(1.0);
        $this->ship($orderId, $this->sourceOf($this->stockA));
        $this->moveWebsiteTo($this->stockB);

        self::assertTrue($this->registerReleased($orderId));

        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('registration', $this->obligations($orderId)[0]->settledBy);
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockA), 'the placement on A balances the shipment on A');
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockB), 'nothing on B');
        self::assertNotContains($this->incrementId($orderId), $this->listedOrders(), 'consistent in Magento\'s listing');
    }

    public function test_shipped_on_the_new_stock_after_the_move_the_row_is_refused_and_a_compensation_there_settles_it(): void
    {
        $orderId = $this->owedOrder(1.0);
        $this->moveWebsiteTo($this->stockB);
        $this->ship($orderId, $this->sourceOf($this->stockB));

        self::assertTrue($this->registerReleased($orderId), 'the run completed; the refusal is its result');

        self::assertSame('refused', $this->obligationStatus($orderId));
        $reason = (string) $this->obligations($orderId)[0]->lastError;
        self::assertStringContainsString(sprintf('the website sells from stock %d now and the order was recorded on stock %d', $this->stockB, $this->stockA), $reason);
        self::assertStringContainsString(sprintf('stock %d lacks -1.0000', $this->stockB), $reason);
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockA), 'A untouched');
        self::assertSame(1.0, $this->reservationSum($orderId, $this->stockB), 'the shipment\'s row alone on B');
        self::assertContains($this->incrementId($orderId), $this->listedOrders(), 'Magento\'s listing shows the discrepancy on B');

        $this->append([$this->manualCompensation($orderId, $this->stockB, -1.0)]);
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame('compensation', $this->obligations($orderId)[0]->settledBy);
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockB));
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockA), 'A untouched');
        self::assertNotContains($orderId, $this->orderIdsRefused());
    }

    public function test_moved_before_any_shipment_the_row_is_refused_and_a_compensation_on_the_new_stock_settles_it(): void
    {
        $orderId = $this->owedOrder(1.0);
        $this->moveWebsiteTo($this->stockB);

        self::assertTrue($this->registerReleased($orderId));

        self::assertSame('refused', $this->obligationStatus($orderId));
        self::assertStringContainsString(sprintf('stock %d lacks -1.0000', $this->stockB), (string) $this->obligations($orderId)[0]->lastError);
        self::assertSame([], $this->reservationRows($orderId), 'nothing placed anywhere');
        self::assertContains($this->incrementId($orderId), $this->listedOrders());

        $this->append([$this->manualCompensation($orderId, $this->stockB, -1.0)]);
        self::assertSame('done', $this->obligationStatus($orderId));
        self::assertSame(-1.0, $this->reservationSum($orderId, $this->stockB));
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockA));
    }

    public function test_a_shipment_split_over_both_stocks_is_refused_naming_both_and_settled_after_the_second_compensation(): void
    {
        $orderId = $this->owedOrder(2.0);
        $itemId = $this->firstItemId($orderId);
        $this->ship($orderId, $this->sourceOf($this->stockA), [$itemId => 1.0]);
        $this->moveWebsiteTo($this->stockB);
        $this->ship($orderId, $this->sourceOf($this->stockB), [$itemId => 1.0]);

        self::assertTrue($this->registerReleased($orderId));

        self::assertSame('refused', $this->obligationStatus($orderId));
        $reason = (string) $this->obligations($orderId)[0]->lastError;
        self::assertStringContainsString(sprintf('stock %d lacks -1.0000', $this->stockA), $reason);
        self::assertStringContainsString(sprintf('stock %d lacks -1.0000', $this->stockB), $reason);
        self::assertSame(1.0, $this->reservationSum($orderId, $this->stockA), 'nothing placed: placing on A alone would leave the row half repaired');
        self::assertSame(1.0, $this->reservationSum($orderId, $this->stockB));

        $this->append([$this->manualCompensation($orderId, $this->stockA, -1.0)]);
        self::assertSame('refused', $this->obligationStatus($orderId), 'B still lacks one: not settled yet');
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockA));

        $this->append([$this->manualCompensation($orderId, $this->stockB, -1.0)]);
        self::assertSame('done', $this->obligationStatus($orderId), 'settled after the second');
        self::assertSame(0.0, $this->reservationSum($orderId, $this->stockB));
        self::assertNotContains($orderId, $this->orderIdsRefused());
    }

    public function test_two_skus_refused_after_a_move_are_settled_one_by_one(): void
    {
        $orderId = (int) $this->placeOrderWithLines([self::SKU => 1.0, self::CHILD_SKU => 1.0], reserveStock: false)->getEntityId();
        $this->insertHeld($orderId, 1.0, self::SKU);
        $this->insertHeld($orderId, 1.0, self::CHILD_SKU);
        $this->moveWebsiteTo($this->stockB);

        self::assertTrue($this->registerReleased($orderId));
        self::assertSame('refused', $this->obligationStatus($orderId, self::SKU));
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU));

        $this->append([$this->manualCompensation($orderId, $this->stockB, -1.0, self::SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::SKU), 'its row only');
        self::assertSame('refused', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertContains($orderId, $this->orderIdsRefused(), 'the order stays listed with the second');

        $this->append([$this->manualCompensation($orderId, $this->stockB, -1.0, self::CHILD_SKU)]);
        self::assertSame('done', $this->obligationStatus($orderId, self::CHILD_SKU));
        self::assertNotContains($orderId, $this->orderIdsRefused(), 'complete');
    }

    /**
     * An order recorded on the website's stock of the moment, its registration still owed.
     */
    private function owedOrder(float $qty): int
    {
        $orderId = (int) $this->placeOrder($qty, reserveStock: false)->getEntityId();
        // held while the shipments save, so the registration under test is the one the test runs
        $this->insertHeld($orderId, $qty);
        return $orderId;
    }

    /**
     * The order's rows given their mode back and registered by the test.
     */
    private function registerReleased(int $orderId): bool
    {
        $this->releaseRegistration($orderId);
        return $this->register($orderId);
    }

    /**
     * The website sells from the given stock from now on, the way an admin
     * reassigns it. Magento caches the mapping for the length of a request;
     * the next request reads it afresh, and so does this process from here.
     */
    private function moveWebsiteTo(int $stockId): void
    {
        $this->connection()->update(
            $this->table('inventory_stock_sales_channel'),
            ['stock_id' => $stockId],
            ['type = ?' => 'website', 'code = ?' => self::WEBSITE_CODE]
        );
        foreach ([GetStockBySalesChannelInterface::class, GetAssignedStockIdForWebsiteInterface::class] as $service) {
            $instance = self::$objectManager->get($service);
            if ($instance instanceof ResetAfterRequestInterface) {
                $instance->_resetState();
            }
        }
    }

    /**
     * The first source linked to the stock, to ship from.
     */
    private function sourceOf(int $stockId): string
    {
        $connection = $this->connection();
        $select = $connection->select()->from($this->table('inventory_source_stock_link'), ['source_code'])
            ->where('stock_id = ?', $stockId)->order('priority')->limit(1);
        $source = (string) $connection->fetchOne($select);
        self::assertNotSame('', $source, sprintf('stock %d has a source', $stockId));
        return $source;
    }

    private function firstItemId(int $orderId): int
    {
        foreach ($this->order($orderId)->getItems() as $item) {
            return (int) $item->getItemId();
        }
        self::fail('the order has a line');
    }

    private function incrementId(int $orderId): string
    {
        return (string) $this->order($orderId)->getIncrementId();
    }

    /**
     * The increment ids Magento's inconsistency listing names.
     *
     * @return string[]
     */
    private function listedOrders(): array
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
