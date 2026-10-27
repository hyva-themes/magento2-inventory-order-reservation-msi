<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Compensation;

use Hyva\InventoryOrderReservationMsi\Model\Compensation\CompensationRunNotes;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use PHPUnit\Framework\TestCase;

class CompensationRunNotesTest extends TestCase
{
    public function test_closed_notes_record_nothing(): void
    {
        $notes = new CompensationRunNotes();

        $notes->noteOutcome(9, $this->partial());

        self::assertFalse($notes->isOpen());
        self::assertSame([], $notes->outcomes());
    }

    public function test_open_notes_record_outcomes_and_keep_them_readable_after_closing(): void
    {
        $notes = new CompensationRunNotes();
        $outcome = $this->partial();

        $notes->open();
        $notes->noteOutcome(9, $outcome);
        $notes->close();

        self::assertFalse($notes->isOpen());
        self::assertSame([['orderId' => 9, 'outcome' => $outcome]], $notes->outcomes());
    }

    public function test_opening_again_starts_empty(): void
    {
        $notes = new CompensationRunNotes();
        $notes->open();
        $notes->noteOutcome(9, $this->partial());

        $notes->open();

        self::assertTrue($notes->isOpen());
        self::assertSame([], $notes->outcomes());
    }

    private function partial(): Outcome
    {
        return new Outcome(Outcome::PARTIAL, 'mug', 2, -1.0, -3.0, -2.0, false);
    }
}
