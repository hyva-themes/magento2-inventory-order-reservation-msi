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
 * A manual compensation that names a stock the order is not registered
 * on writes nothing, on either stock.
 */
class WrongStockCompensationTest extends StoreCase
{
    public function test_a_compensation_on_another_stock_is_refused_and_both_stocks_are_untouched(): void
    {
        $this->requireInventory();
        $stockA = $this->websiteStockId();
        $stockB = $this->otherStockId();
        $order = $this->placeOrder(1.0, reserveStock: true);
        $orderId = (int) $order->getEntityId();
        self::assertSame('done', $this->obligationStatus($orderId), 'the placement registered the order');
        self::assertSame(-1.0, $this->reservationSum($orderId, $stockA), 'one unit reserved on the website stock');
        self::assertSame(0.0, $this->reservationSum($orderId, $stockB));
        $rowsBefore = count($this->reservationRows($orderId));

        try {
            $this->append([$this->manualCompensation($orderId, $stockB, -1.0)]);
            self::fail('the wrong-stock compensation must be refused');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString(
                sprintf('stock %d named, the order is registered on stock %d', $stockB, $stockA),
                $e->getMessage()
            );
        }

        self::assertSame(-1.0, $this->reservationSum($orderId, $stockA), 'stock A unchanged');
        self::assertSame(0.0, $this->reservationSum($orderId, $stockB), 'stock B unchanged');
        self::assertCount($rowsBefore, $this->reservationRows($orderId), 'no row written');
        self::assertSame('done', $this->obligationStatus($orderId));
    }

    public function test_the_same_request_on_the_right_stock_is_refused_as_already_balanced_and_writes_nothing(): void
    {
        $this->requireInventory();
        $stockA = $this->websiteStockId();
        $orderId = (int) $this->placeOrder(1.0)->getEntityId();
        $rowsBefore = count($this->reservationRows($orderId));

        $this->appendExpectingBalanced($orderId, $stockA, -1.0);

        self::assertSame(-1.0, $this->reservationSum($orderId, $stockA));
        self::assertCount($rowsBefore, $this->reservationRows($orderId), 'already balanced: nothing written');
    }

    public function test_a_sku_the_order_owes_nothing_on_is_refused(): void
    {
        $this->requireInventory();
        $orderId = (int) $this->placeOrder(1.0)->getEntityId();

        $unknownSku = 'no-such-sku-on-this-order';
        try {
            $this->append([$this->manualCompensation($orderId, $this->websiteStockId(), -1.0, $unknownSku)]);
            self::fail('a sku the order owes nothing on must be refused');
        } catch (CouldNotSaveException $e) {
            self::assertStringContainsString('owes nothing on this sku', $e->getMessage());
        }
        self::assertSame([], $this->reservationRows($orderId, $unknownSku));
    }
}
