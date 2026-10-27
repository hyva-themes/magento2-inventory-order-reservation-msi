<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Plugin\Console;

use Hyva\InventoryOrderReservationMsi\Model\Compensation\CompensationRunNotes;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\InventoryReservationCli\Command\CreateCompensations;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento's compensation command runs as it is: its header, its standard
 * input, its parse errors, its line per row, its exit code, and every
 * other plugin on it. This plugin only opens the run's notes for the
 * command's duration and prints them under Magento's output: the remainder
 * still owed after a partial compensation, a registration that completed
 * first, and the store setting under which Magento writes no reservation
 * at all while its loop still prints every row as compensated.
 */
class ReportCompensationsPlugin
{
    public function __construct(
        private readonly CompensationRunNotes $notes,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The result is Magento's, whatever happens here: a note that cannot be
     * rendered is logged, and an exception out of Magento's loop closes the
     * notes on its way out.
     */
    public function aroundExecute(
        CreateCompensations $subject,
        callable $proceed,
        InputInterface $input,
        OutputInterface $output
    ): int {
        $this->notes->open();
        try {
            $result = (int) $proceed($input, $output);
        } finally {
            $this->notes->close();
        }
        try {
            $this->report($output);
        } catch (\Throwable $e) {
            $this->logger->warning('The compensation notes could not be printed', ['exception' => $e->getMessage()]);
        }
        return $result;
    }

    private function report(OutputInterface $output): void
    {
        $registered = [];
        foreach ($this->notes->outcomes() as ['orderId' => $orderId, 'outcome' => $outcome]) {
            if ($outcome->registrationRan && !isset($registered[$orderId])) {
                $registered[$orderId] = true;
                $output->writeln(sprintf('  - Order %d: its owed registration completed before the compensation', $orderId));
            }
            if ($outcome->kind === Outcome::PARTIAL) {
                $output->writeln(sprintf(
                    '  - Order %d, product <comment>%s</comment>: %+.4f still owed',
                    $orderId,
                    OutputFormatter::escape($outcome->sku),
                    $outcome->remainingAfter
                ));
            }
        }
        if (!$this->stockConfiguration->canSubtractQty()) {
            // Magento's own filter on the reservation writer drops every row
            // under this setting, after its loop has printed the row as compensated
            $output->writeln(
                '  - "Decrease Stock When Order is Placed" is off: Magento keeps no reservations, nothing was written'
            );
        }
    }
}
