<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

/**
 * What one run of Magento's compensation command did beyond what Magento
 * prints itself: the coordinated outcomes. The command plugin opens the
 * notes for the length of the command and prints them under Magento's
 * output; the coordination plugin fills them. Closed, the notes record
 * nothing, so an order placement or a shipment that appends reservations
 * pays nothing here.
 */
class CompensationRunNotes
{
    private bool $open = false;

    /** @var array<int, array{orderId: int, outcome: Outcome}> */
    private array $outcomes = [];

    public function open(): void
    {
        $this->open = true;
        $this->outcomes = [];
    }

    /**
     * Stops recording; what was recorded stays readable until the next open.
     */
    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function noteOutcome(int $orderId, Outcome $outcome): void
    {
        if ($this->open) {
            $this->outcomes[] = ['orderId' => $orderId, 'outcome' => $outcome];
        }
    }

    /** @return array<int, array{orderId: int, outcome: Outcome}> */
    public function outcomes(): array
    {
        return $this->outcomes;
    }
}
