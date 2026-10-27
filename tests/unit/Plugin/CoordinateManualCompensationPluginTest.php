<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Plugin;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\CompensationRunNotes;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use Hyva\InventoryOrderReservationMsi\Plugin\Inventory\CoordinateManualCompensationPlugin;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CoordinateManualCompensationPluginTest extends TestCase
{
    /** A manual compensation for order 9, the order the module holds an obligation for. */
    private const MANUAL_FOR_9 = '{"event_type":"manual_compensation","object_type":"order","object_id":9}';

    public function test_rows_of_other_kinds_and_for_unknown_orders_pass_through(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->expects(self::never())->method('apply');
        $plugin = $this->plugin($coordinated);
        $passed = [];
        $proceed = static function (array $rows) use (&$passed): void {
            $passed = $rows;
        };
        $shipment = $this->reservation('{"event_type":"shipment_created","object_type":"order","object_id":"9"}');
        $manualForUnknown = $this->reservation('{"event_type":"manual_compensation","object_type":"order","object_id":77}');

        $plugin->aroundExecute($this->subject(), $proceed, [$shipment, $manualForUnknown]);

        self::assertSame([$shipment, $manualForUnknown], $passed);
    }

    public function test_a_manual_compensation_for_an_owed_order_is_coordinated_and_a_refusal_is_an_exception(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->expects(self::once())->method('apply')->with(9, self::anything())
            ->willReturn(new Outcome(Outcome::REFUSED, 'mug', 2, -2.0, -1.0, -1.0, true, 'more than remains'));
        $plugin = $this->plugin($coordinated);
        $proceedCalled = false;
        $proceed = static function () use (&$proceedCalled): void {
            $proceedCalled = true;
        };

        try {
            $plugin->aroundExecute($this->subject(), $proceed, [$this->reservation(self::MANUAL_FOR_9)]);
            self::fail('a refusal must throw');
        } catch (CouldNotSaveException $e) {
            self::assertStringStartsWith('Compensation for order 9: registration completed first; refused: more than remains', $e->getMessage());
        }
        self::assertFalse($proceedCalled, 'nothing else to write');
    }

    public function test_an_already_balanced_order_is_refused_with_nothing_written(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->expects(self::once())->method('apply')->with(9, self::anything())
            ->willReturn(new Outcome(Outcome::ALREADY_BALANCED, 'mug', 2, -1.0, 0.0, 0.0, false));
        $plugin = $this->plugin($coordinated);

        try {
            $plugin->aroundExecute($this->subject(), $this->noProceed(), [$this->reservation(self::MANUAL_FOR_9)]);
            self::fail('an already balanced order must refuse the request');
        } catch (CouldNotSaveException $e) {
            self::assertSame('Compensation for order 9: already balanced, nothing written', $e->getMessage());
        }
    }

    public function test_a_failure_under_the_lock_is_logged_and_reaches_the_caller_without_its_text(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->method('apply')->willThrowException(new \RuntimeException('SQLSTATE[45000]: simulated failure after the compensation write'));
        $notes = new CompensationRunNotes();
        $notes->open();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Coordinated compensation failed', self::callback(
            static fn (array $context): bool => $context['order_id'] === 9 && $context['exception'] instanceof \RuntimeException
        ));
        $plugin = $this->plugin($coordinated, $notes, $logger);

        try {
            $plugin->aroundExecute($this->subject(), $this->noProceed(), [$this->reservation(self::MANUAL_FOR_9)]);
            self::fail('the failure must surface');
        } catch (CouldNotSaveException $e) {
            self::assertSame('Compensation for order 9 was not written; the exception log has the cause.', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        self::assertSame([], $notes->outcomes(), 'nothing was judged, nothing to note');
    }

    public function test_a_failure_the_module_words_itself_keeps_its_text(): void
    {
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->method('apply')->willThrowException(new LocalizedException(__('Magento Inventory is not available on this store.')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $plugin = $this->plugin($coordinated, null, $logger);

        try {
            $plugin->aroundExecute($this->subject(), $this->noProceed(), [$this->reservation(self::MANUAL_FOR_9)]);
            self::fail('the failure must surface');
        } catch (CouldNotSaveException $e) {
            self::assertSame('Compensation for order 9 was not written: Magento Inventory is not available on this store.', $e->getMessage());
        }
    }

    public function test_open_notes_record_the_outcomes_and_closed_notes_nothing(): void
    {
        $outcome = new Outcome(Outcome::APPLIED, 'mug', 2, -1.0, -1.0, 0.0, true);
        $coordinated = $this->createMock(CoordinatedCompensation::class);
        $coordinated->method('apply')->willReturn($outcome);
        $notes = new CompensationRunNotes();
        $plugin = $this->plugin($coordinated, $notes);
        $rows = [
            $this->reservation('{"event_type":"shipment_created","object_type":"order","object_id":"77"}'),
            $this->reservation(self::MANUAL_FOR_9),
        ];

        $plugin->aroundExecute($this->subject(), $this->noProceed(), $rows);
        self::assertSame([], $notes->outcomes(), 'closed: a placement or a shipment records nothing');

        $notes->open();
        $plugin->aroundExecute($this->subject(), $this->noProceed(), $rows);
        self::assertSame([['orderId' => 9, 'outcome' => $outcome]], $notes->outcomes());
    }

    /**
     * The plugin over a module that holds an obligation for order 9 and no other.
     */
    private function plugin(
        CoordinatedCompensation $coordinated,
        ?CompensationRunNotes $notes = null,
        ?LoggerInterface $logger = null
    ): CoordinateManualCompensationPlugin {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('hasRowsFor')->willReturnCallback(static fn (int $orderId): bool => $orderId === 9);
        return new CoordinateManualCompensationPlugin(
            $coordinated,
            $obligations,
            new Json(),
            $notes ?? new CompensationRunNotes(),
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    private function subject(): AppendReservationsInterface
    {
        return $this->createMock(AppendReservationsInterface::class);
    }

    private function noProceed(): \Closure
    {
        return static function (): void {
        };
    }

    private function reservation(string $metadata): ReservationInterface
    {
        $reservation = $this->createMock(ReservationInterface::class);
        $reservation->method('getMetadata')->willReturn($metadata);
        $reservation->method('getSku')->willReturn('mug');
        $reservation->method('getStockId')->willReturn(2);
        $reservation->method('getQuantity')->willReturn(-1.0);
        return $reservation;
    }
}
