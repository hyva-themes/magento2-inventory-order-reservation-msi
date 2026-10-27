<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Plugin\Console;

use Hyva\InventoryOrderReservationMsi\Model\Compensation\CompensationRunNotes;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use Hyva\InventoryOrderReservationMsi\Plugin\Console\ReportCompensationsPlugin;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\InventoryReservationCli\Command\CreateCompensations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ReportCompensationsPluginTest extends TestCase
{
    public function test_the_notes_are_open_during_magento_s_loop_and_closed_after_and_the_result_is_magento_s(): void
    {
        $notes = new CompensationRunNotes();
        $plugin = $this->plugin($notes);
        $openDuring = null;
        $proceed = static function () use ($notes, &$openDuring): int {
            $openDuring = $notes->isOpen();
            return 1;
        };

        $result = $plugin->aroundExecute($this->command(), $proceed, $this->createMock(InputInterface::class), new BufferedOutput());

        self::assertTrue($openDuring, 'open while Magento runs');
        self::assertFalse($notes->isOpen(), 'closed afterwards');
        self::assertSame(1, $result, 'Magento\'s exit code, untouched');
    }

    public function test_an_exception_out_of_the_loop_closes_the_notes_on_its_way_out(): void
    {
        $notes = new CompensationRunNotes();
        $plugin = $this->plugin($notes);
        $proceed = static function (): int {
            throw new \RuntimeException('the loop died');
        };

        try {
            $plugin->aroundExecute($this->command(), $proceed, $this->createMock(InputInterface::class), new BufferedOutput());
            self::fail('the exception must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('the loop died', $e->getMessage());
        }
        self::assertFalse($notes->isOpen());
    }

    public function test_the_notes_are_printed_under_magento_s_output(): void
    {
        $notes = new CompensationRunNotes();
        $plugin = $this->plugin($notes);
        $proceed = static function (InputInterface $input, OutputInterface $output) use ($notes): int {
            $output->writeln('Following reservations were created:');
            $notes->noteOutcome(9, new Outcome(Outcome::PARTIAL, 'mug', 2, -1.0, -3.0, -2.0, true));
            $notes->noteOutcome(9, new Outcome(Outcome::APPLIED, 'cup', 2, -1.0, -1.0, 0.0, true));
            return 0;
        };
        $output = new BufferedOutput();

        $plugin->aroundExecute($this->command(), $proceed, $this->createMock(InputInterface::class), $output);

        self::assertSame(
            [
                'Following reservations were created:',
                '  - Order 9: its owed registration completed before the compensation',
                '  - Order 9, product mug: -2.0000 still owed',
            ],
            explode("\n", rtrim($output->fetch()))
        );
    }

    public function test_the_store_setting_under_which_magento_writes_nothing_is_named_once(): void
    {
        $notes = new CompensationRunNotes();
        $plugin = $this->plugin($notes, canSubtract: false);
        $proceed = static function (InputInterface $input, OutputInterface $output): int {
            $output->writeln('  - Product mug was compensated by -1.000000 for stock 2');
            return 0;
        };
        $output = new BufferedOutput();

        $plugin->aroundExecute($this->command(), $proceed, $this->createMock(InputInterface::class), $output);

        self::assertSame(
            [
                '  - Product mug was compensated by -1.000000 for stock 2',
                '  - "Decrease Stock When Order is Placed" is off: Magento keeps no reservations, nothing was written',
            ],
            explode("\n", rtrim($output->fetch()))
        );
    }

    public function test_a_note_that_cannot_be_rendered_is_logged_and_the_result_stays(): void
    {
        $notes = new CompensationRunNotes();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $plugin = $this->plugin($notes, logger: $logger);
        $proceed = static function () use ($notes): int {
            $notes->noteOutcome(9, new Outcome(Outcome::PARTIAL, 'mug', 2, -1.0, -3.0, -2.0, false));
            return 0;
        };
        $output = $this->createMock(OutputInterface::class);
        $output->method('writeln')->willThrowException(new \RuntimeException('closed pipe'));

        self::assertSame(0, $plugin->aroundExecute($this->command(), $proceed, $this->createMock(InputInterface::class), $output));
    }

    private function plugin(CompensationRunNotes $notes, ?LoggerInterface $logger = null, bool $canSubtract = true): ReportCompensationsPlugin
    {
        $configuration = $this->createMock(StockConfigurationInterface::class);
        $configuration->method('canSubtractQty')->willReturn($canSubtract);
        return new ReportCompensationsPlugin($notes, $configuration, $logger ?? $this->createMock(LoggerInterface::class));
    }

    private function command(): CreateCompensations
    {
        return $this->createMock(CreateCompensations::class);
    }
}
