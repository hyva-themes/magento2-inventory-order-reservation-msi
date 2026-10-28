<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit\Compensation;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Model\StockRegistration;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\TestCase;

class CoordinatedCompensationTest extends TestCase
{
    /** @var array<int, array{0: object, 1: int}> */
    private array $writers = [];

    public function test_registration_completes_first_and_a_balanced_order_takes_no_row(): void
    {
        $writer = $this->writer(expectRows: 0);
        $service = $this->service(registrationRan: true, remaining: 0.0, writer: $writer);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::ALREADY_BALANCED, $outcome->kind);
        self::assertTrue($outcome->registrationRan);
        self::assertStringContainsString('registration completed first', $outcome->describe());
    }

    public function test_the_right_amount_is_applied(): void
    {
        $writer = $this->writer(expectRows: 1);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $writer);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::APPLIED, $outcome->kind);
        self::assertSame(0.0, $outcome->remainingAfter);
    }

    public function test_a_partial_amount_is_applied_and_the_remainder_reported(): void
    {
        $service = $this->service(registrationRan: false, remaining: -3.0, writer: $this->writer(expectRows: 1));

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::PARTIAL, $outcome->kind);
        self::assertSame(-2.0, $outcome->remainingAfter);
        self::assertStringContainsString('-2.0000 still owed', $outcome->describe());
    }

    public function test_the_wrong_direction_and_too_much_are_refused_without_a_row(): void
    {
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0));

        $wrongWay = $service->apply(9, $this->reservation('mug', 2, 1.0));
        $tooMuch = $service->apply(9, $this->reservation('mug', 2, -2.0));

        self::assertTrue($wrongWay->isRefused());
        self::assertSame('wrong direction', $wrongWay->reason);
        self::assertTrue($tooMuch->isRefused());
        self::assertSame('more than remains', $tooMuch->reason);
        self::assertStringContainsString('-1.0000', $tooMuch->describe());
    }

    public function test_a_stock_the_order_expects_nothing_on_and_holds_no_row_on_is_balanced_and_takes_nothing(): void
    {
        // the obligation says stock 2; the request names stock 1, where Magento
        // counts nothing for the order and no row exists: judged there, balanced
        $remainingService = $this->createMock(RemainingInconsistency::class);
        $remainingService->expects(self::once())->method('forOrderSku')
            ->with(self::anything(), 'mug', 1)
            ->willReturn(['expected' => 0.0, 'actual' => 0.0, 'remaining' => 0.0]);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0), remainingService: $remainingService);

        $outcome = $service->apply(9, $this->reservation('mug', 1, -1.0));

        self::assertSame(Outcome::ALREADY_BALANCED, $outcome->kind);
        self::assertSame(1, $outcome->stockId);
    }

    public function test_a_compensation_on_the_stock_the_website_moved_to_repairs_what_remains_there(): void
    {
        // recorded on stock 2, the website sells from stock 1 now and the shipment's row sits there: stock 1 lacks -1
        $remainingService = $this->createMock(RemainingInconsistency::class);
        $remainingService->method('forOrderSku')->with(self::anything(), 'mug', 1)
            ->willReturn(['expected' => 0.0, 'actual' => 1.0, 'remaining' => -1.0]);
        $remainingService->method('remaindersForRow')->willReturn([1 => 0.0, 2 => 0.0]);
        $obligations = $this->refusedRows();
        $obligations->expects(self::once())->method('markRowDone')->with(9, 'mug', Obligation::SETTLED_BY_COMPENSATION);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 1), remainingService: $remainingService, obligations: $obligations);

        $outcome = $service->apply(9, $this->reservation('mug', 1, -1.0));

        self::assertSame(Outcome::APPLIED, $outcome->kind);
        self::assertSame(1, $outcome->stockId);
    }

    public function test_a_refused_row_is_settled_only_when_nothing_remains_on_any_stock(): void
    {
        // one unit shipped on each stock around a move: stock 2 repaired, stock 1 still lacks one
        $remainingService = $this->createStub(RemainingInconsistency::class);
        $remainingService->method('forOrderSku')->willReturn(['expected' => 0.0, 'actual' => 1.0, 'remaining' => -1.0]);
        $remainingService->method('remaindersForRow')->willReturn([1 => -1.0, 2 => 0.0]);
        $obligations = $this->refusedRows();
        $obligations->expects(self::never())->method('markRowDone');
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 1), remainingService: $remainingService, obligations: $obligations);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::APPLIED, $outcome->kind, 'applied on stock 2, the row not settled yet');
    }

    public function test_a_renamed_row_takes_no_compensation_whether_refused_before_or_by_the_registration_first(): void
    {
        // recorded as mug for product 10, which the catalog calls mug-new now;
        // by product the order still owes one, so the amount alone would pass
        $owed = new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY, productId: 10);
        $refused = new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY, Obligation::STATUS_REFUSED, productId: 10);
        foreach (['refused before' => [$refused, null, false], 'refused by the registration' => [$owed, $refused, true]] as $case => [$row, $after, $ran]) {
            $obligations = $this->rows($row, $after);
            $obligations->expects(self::never())->method('markRowDone');
            $service = $this->service(registrationRan: $ran, remaining: -1.0, writer: $this->writer(expectRows: 0), obligations: $obligations, renamedTo: 'mug-new');

            $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

            self::assertTrue($outcome->isRefused(), $case);
            self::assertStringContainsString('renamed to mug-new', $outcome->reason, $case);
            self::assertStringContainsString('inventory:order-reservation:settle 9', $outcome->reason, $case);
        }
    }

    public function test_a_compensation_is_refused_while_a_reservation_of_an_ambiguous_order_number_exists(): void
    {
        $obligations = $this->refusedRows();
        $obligations->expects(self::never())->method('markRowDone');
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0), obligations: $obligations, ambiguous: true);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertTrue($outcome->isRefused());
        self::assertStringContainsString('names only the order number', $outcome->reason);
    }

    public function test_a_refused_row_is_judged_without_registering_the_orders_other_rows(): void
    {
        $registration = $this->createMock(StockRegistration::class);
        $registration->expects(self::never())->method('registerLocked');
        $obligations = $this->refusedRows();
        $obligations->expects(self::once())->method('markRowDone')->with(9, 'mug', Obligation::SETTLED_BY_COMPENSATION);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 1), obligations: $obligations, registration: $registration);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::APPLIED, $outcome->kind);
        self::assertFalse($outcome->registrationRan);
    }

    public function test_a_row_still_owed_after_the_registration_ran_is_refused_as_pending(): void
    {
        // the handler could not be built, or an Inventory module is missing: the row stays the module's
        $owed = new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0), obligations: $this->rows($owed, null));

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertTrue($outcome->isRefused());
        self::assertStringContainsString('has not run', $outcome->reason);
    }

    public function test_a_registration_that_fails_reaches_the_caller_with_nothing_judged(): void
    {
        $registration = $this->createStub(StockRegistration::class);
        $registration->method('registerLocked')->willThrowException(new LocalizedException(__('Some of the products are out of stock.')));
        $service = $this->service(registrationRan: true, remaining: -1.0, writer: $this->writer(expectRows: 0), registration: $registration);

        $this->expectException(LocalizedException::class);
        $service->apply(9, $this->reservation('mug', 2, -1.0));
    }

    public function test_a_sku_the_order_owes_nothing_on_is_refused(): void
    {
        $remainingService = $this->createMock(RemainingInconsistency::class);
        $remainingService->expects(self::never())->method('forOrderSku');
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0), remainingService: $remainingService);

        $outcome = $service->apply(9, $this->reservation('vase', 2, -1.0));

        self::assertTrue($outcome->isRefused());
        self::assertSame('the order owes nothing on this sku', $outcome->reason);
    }

    public function test_a_legacy_obligation_has_no_stock_to_compensate(): void
    {
        $remainingService = $this->createMock(RemainingInconsistency::class);
        $remainingService->expects(self::never())->method('forOrderSku');
        $service = $this->service(
            registrationRan: false,
            remaining: -1.0,
            writer: $this->writer(expectRows: 0),
            remainingService: $remainingService,
            obligation: new Obligation(9, 'mug', 'simple', 1.0, 1, null, Obligation::MODE_LEGACY, Obligation::STATUS_DONE)
        );

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertTrue($outcome->isRefused());
        self::assertStringContainsString('legacy stock item', $outcome->reason);
    }

    public function test_a_compensation_that_balances_a_refused_sku_settles_that_row_only(): void
    {
        $obligations = $this->refusedRows();
        $obligations->expects(self::once())->method('markRowDone')->with(9, 'mug', Obligation::SETTLED_BY_COMPENSATION);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 1), obligations: $obligations);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::APPLIED, $outcome->kind);
    }

    public function test_a_partial_compensation_on_a_refused_sku_settles_nothing_yet(): void
    {
        $obligations = $this->refusedRows();
        $obligations->expects(self::never())->method('markRowDone');
        $service = $this->service(registrationRan: false, remaining: -3.0, writer: $this->writer(expectRows: 1), obligations: $obligations);

        $outcome = $service->apply(9, $this->reservation('mug', 2, -1.0));

        self::assertSame(Outcome::PARTIAL, $outcome->kind);
    }

    public function test_a_writer_that_fails_settles_nothing(): void
    {
        $obligations = $this->refusedRows();
        $obligations->expects(self::never())->method('markRowDone');
        $writer = new class implements AppendReservationsInterface {
            public function execute(array $reservations): void
            {
                throw new \RuntimeException('the reservation table is gone');
            }
        };
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $writer, obligations: $obligations);

        $this->expectException(\RuntimeException::class);
        $service->apply(9, $this->reservation('mug', 2, -1.0));
    }

    public function test_two_database_connections_refuse_the_write(): void
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('isOneConnection')->willReturn(false);
        $obligations->method('forOrder')->with(9)->willReturn([
            new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY, Obligation::STATUS_DONE),
        ]);
        $service = $this->service(registrationRan: false, remaining: -1.0, writer: $this->writer(expectRows: 0), obligations: $obligations);

        $this->expectException(LocalizedException::class);
        $service->apply(9, $this->reservation('mug', 2, -1.0));
    }

    /**
     * The resource answering the row, then the row after the registration when one is given.
     */
    private function rows(Obligation $row, ?Obligation $afterRegistration): ObligationResource
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('isOneConnection')->willReturn(true);
        $calls = 0;
        $obligations->method('forOrder')->with(9)->willReturnCallback(
            static function () use ($row, $afterRegistration, &$calls): array {
                $calls++;
                return [$calls > 1 && $afterRegistration !== null ? $afterRegistration : $row];
            }
        );
        return $obligations;
    }

    private function done(Obligation $row): Obligation
    {
        return new Obligation(
            $row->orderId,
            $row->sku,
            $row->productType,
            $row->qtyOrdered,
            $row->websiteId,
            $row->stockId,
            $row->mode,
            Obligation::STATUS_DONE,
            Obligation::SETTLED_BY_REGISTRATION
        );
    }

    private function refusedRows(): ObligationResource
    {
        $obligations = $this->createMock(ObligationResource::class);
        $obligations->method('isOneConnection')->willReturn(true);
        $obligations->method('forOrder')->with(9)->willReturn([
            new Obligation(9, 'mug', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY, Obligation::STATUS_REFUSED),
            new Obligation(9, 'vase', 'simple', 1.0, 1, 2, Obligation::MODE_INVENTORY, Obligation::STATUS_REFUSED),
        ]);
        return $obligations;
    }

    private function service(
        bool $registrationRan,
        float $remaining,
        AppendReservationsInterface $writer,
        ?RemainingInconsistency $remainingService = null,
        ?Obligation $obligation = null,
        ?ObligationResource $obligations = null,
        ?StockRegistration $registration = null,
        ?string $renamedTo = null,
        bool $ambiguous = false
    ): CoordinatedCompensation {
        $mutex = $this->createStub(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(static fn (int $orderId, callable $callable, array $args) => $callable(...$args));
        if ($registration === null) {
            $registration = $this->createStub(StockRegistration::class);
            $registration->method('registerLocked')->willReturn($registrationRan);
        }
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($this->createStub(OrderInterface::class));
        if ($remainingService === null) {
            $remainingService = $this->createStub(RemainingInconsistency::class);
            $remainingService->method('forOrderSku')->willReturn(['expected' => $remaining, 'actual' => 0.0, 'remaining' => $remaining]);
            $remainingService->method('renamedTo')->willReturn($renamedTo);
            $remainingService->method('hasAmbiguousRows')->willReturn($ambiguous);
            // after a write of the whole remainder nothing is left on the one stock; a partial write leaves the rest
            $remainingService->method('remaindersForRow')->willReturnCallback(
                static fn (): array => [2 => $remaining + 1.0 < -Obligation::TOLERANCE ? round($remaining + 1.0, 4) : 0.0]
            );
        }
        if ($obligations === null) {
            // the named row is read first: owed when the registration is to
            // run first and done after it; done from the start otherwise
            $row = $obligation ?? new Obligation(
                9,
                'mug',
                'simple',
                1.0,
                1,
                2,
                Obligation::MODE_INVENTORY,
                $registrationRan ? Obligation::STATUS_OWED : Obligation::STATUS_DONE
            );
            $obligations = $this->rows($row, $registrationRan ? $this->done($row) : null);
        }
        return new CoordinatedCompensation($mutex, $registration, $orders, $remainingService, $writer, $obligations);
    }

    private function writer(int $expectRows): AppendReservationsInterface
    {
        $writer = new class implements AppendReservationsInterface {
            public int $calls = 0;
            public function execute(array $reservations): void
            {
                $this->calls += count($reservations);
            }
        };
        $this->writers[] = [$writer, $expectRows];
        return $writer;
    }

    protected function tearDown(): void
    {
        foreach ($this->writers as [$writer, $expected]) {
            self::assertSame($expected, $writer->calls, 'rows written');
        }
        $this->writers = [];
    }

    private function reservation(string $sku, int $stockId, float $qty): ReservationInterface
    {
        $reservation = $this->createStub(ReservationInterface::class);
        $reservation->method('getSku')->willReturn($sku);
        $reservation->method('getStockId')->willReturn($stockId);
        $reservation->method('getQuantity')->willReturn($qty);
        $reservation->method('getMetadata')->willReturn('{"event_type":"manual_compensation","object_type":"order","object_id":9}');
        return $reservation;
    }
}
