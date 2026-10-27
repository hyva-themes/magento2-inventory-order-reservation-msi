<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Integration;

use Hyva\InventoryOrderReservation\Test\Integration\StoreCase;
use Magento\InventoryReservationCli\Command\CreateCompensations;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Magento's compensation command run as an operator runs it, one process
 * per invocation: Magento's own loop prints its lines and decides the exit
 * code; the module adds its notes under them.
 */
class CompensationCommandTest extends StoreCase
{
    private const COMMAND = 'inventory:reservation:create-compensations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireInventory();
        if (!class_exists(CreateCompensations::class)) {
            self::markTestSkipped('Magento_InventoryReservationCli is not installed');
        }
    }

    public function test_written_rows_exit_zero_and_a_partial_one_gets_its_remainder_noted(): void
    {
        $stock = $this->websiteStockId();
        $one = $this->placeOrder(1.0);
        $two = $this->placeOrder(2.0);
        $this->dropPlacements();

        $run = $this->runCommand(self::COMMAND, [$this->argument($one, -1.0, $stock), $this->argument($two, -1.0, $stock)]);

        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('Following reservations were created:', $run['out']);
        self::assertSame(2, substr_count($run['out'], 'was compensated by -1.000000'), $run['out']);
        self::assertStringContainsString(
            sprintf('Order %d, product %s: -1.0000 still owed', $two->getEntityId(), self::SKU),
            $run['out']
        );
        self::assertStringNotContainsString(sprintf('Order %d, product', $one->getEntityId()), $run['out'], 'balanced: no note');
        self::assertSame(-1.0, $this->reservationSum((int) $one->getEntityId(), $stock));
        self::assertSame(-1.0, $this->reservationSum((int) $two->getEntityId(), $stock));
    }

    public function test_a_refused_and_a_balanced_row_exit_one_and_the_written_row_keeps_its_note(): void
    {
        $stock = $this->websiteStockId();
        $shipped = $this->placeOrder(1.0);
        $this->ship((int) $shipped->getEntityId(), $this->websiteSources()[0]);
        $wrongWay = $this->placeOrder(1.0);
        $partial = $this->placeOrder(2.0);
        $this->dropPlacements();

        $run = $this->runCommand(self::COMMAND, [
            $this->argument($shipped, -1.0, $stock),
            $this->argument($wrongWay, 1.0, $stock),
            $this->argument($partial, -1.0, $stock),
        ]);

        self::assertSame(1, $run['exit'], $run['out']);
        self::assertStringContainsString(
            sprintf('Compensation for order %d: already balanced, nothing written', $shipped->getEntityId()),
            $run['out']
        );
        self::assertStringContainsString('refused: wrong direction', $run['out']);
        self::assertSame(1, substr_count($run['out'], 'was compensated by -1.000000'), $run['out']);
        self::assertStringContainsString(
            sprintf('Order %d, product %s: -1.0000 still owed', $partial->getEntityId(), self::SKU),
            $run['out']
        );
        self::assertSame(0.0, $this->reservationSum((int) $shipped->getEntityId(), $stock));
        self::assertSame(0.0, $this->reservationSum((int) $wrongWay->getEntityId(), $stock));
        self::assertSame(-1.0, $this->reservationSum((int) $partial->getEntityId(), $stock));
    }

    public function test_a_malformed_argument_exits_one_with_magento_s_parse_text(): void
    {
        $run = $this->runCommand(self::COMMAND, ['foo']);

        self::assertSame(1, $run['exit'], $run['out']);
        self::assertStringContainsString('Error while parsing argument "foo"', $run['out']);
    }

    public function test_rows_on_standard_input_are_read_as_arguments_are(): void
    {
        $stock = $this->websiteStockId();
        $one = $this->placeOrder(1.0);
        $this->dropPlacements();

        $run = $this->runCommand(self::COMMAND, [], $this->argument($one, -1.0, $stock) . "\n");

        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('was compensated by -1.000000', $run['out']);
        self::assertSame(-1.0, $this->reservationSum((int) $one->getEntityId(), $stock));
    }

    public function test_an_order_the_module_does_not_know_is_magento_s_to_write(): void
    {
        $stock = $this->websiteStockId();
        $unknown = $this->placeOrder(1.0, reserveStock: false);

        $run = $this->runCommand(self::COMMAND, [$this->argument($unknown, -1.0, $stock)]);

        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('was compensated by -1.000000', $run['out']);
        self::assertStringNotContainsString('Order ', $run['out'], 'no note: nothing the module has a say in');
        self::assertSame(-1.0, $this->reservationSum((int) $unknown->getEntityId(), $stock));
    }

    private function argument(OrderInterface $order, float $qty, int $stockId, string $sku = self::SKU): string
    {
        return sprintf('%s:%s:%.4f:%d', $order->getIncrementId(), $sku, $qty, $stockId);
    }
}
