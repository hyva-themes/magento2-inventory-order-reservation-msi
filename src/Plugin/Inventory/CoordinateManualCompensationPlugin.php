<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Plugin\Inventory;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\CompensationRunNotes;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\CoordinatedCompensation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\Outcome;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Psr\Log\LoggerInterface;

/**
 * Every manual compensation that names an order reaches the coordinated
 * service, whichever code appends it through Inventory's reservation
 * writer (Magento's command, a store's own integration that uses the
 * writer); a row written straight into the reservation table is not
 * seen here. Rows of other kinds, and rows for orders this module has
 * no obligation for, pass through untouched. A refused compensation is a
 * CouldNotSaveException naming the discrepancy, so a caller that reads
 * the writer's exceptions learns why; a failure under the order's lock
 * (the registration, the write, the settlement) rolls its transaction
 * back and is the same exception, with the module's own wording kept
 * and a database or runtime failure's text left to the log, since the
 * web API shows a localized message to its caller. Nothing is written
 * either way.
 */
class CoordinateManualCompensationPlugin
{
    private const EVENT_MANUAL_COMPENSATION = 'manual_compensation';
    private const OBJECT_TYPE_ORDER = 'order';

    public function __construct(
        private readonly CoordinatedCompensation $coordinated,
        private readonly ObligationResource $obligations,
        private readonly SerializerInterface $serializer,
        private readonly CompensationRunNotes $notes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ReservationInterface[] $reservations
     * @throws CouldNotSaveException
     */
    public function aroundExecute(AppendReservationsInterface $subject, callable $proceed, array $reservations): void
    {
        if ($this->coordinated->isWriting()) {
            $proceed($reservations);
            return;
        }
        $passThrough = [];
        $coordinated = [];
        foreach ($reservations as $reservation) {
            $orderId = $this->coordinatedOrderId($reservation);
            if ($orderId === null) {
                $passThrough[] = $reservation;
            } else {
                $coordinated[] = [$orderId, $reservation];
            }
        }
        // the rows this module has no say in go first, in one write; each
        // coordinated row is then a whole write of its own under the order's
        // lock, so a refusal leaves only complete writes behind it
        if ($passThrough !== []) {
            $proceed($passThrough);
        }
        foreach ($coordinated as [$orderId, $reservation]) {
            try {
                $outcome = $this->coordinated->apply($orderId, $reservation);
            } catch (LocalizedException $e) {
                // the module's own wording: no Inventory, two connections, no handler
                throw new CouldNotSaveException(__('Compensation for order %1 was not written: %2', $orderId, $e->getMessage()), $e);
            } catch (\Throwable $e) {
                // a database or runtime failure under the lock: its transaction
                // rolled back; the text stays in the log, not in the caller's message
                $this->logger->error('Coordinated compensation failed', ['order_id' => $orderId, 'exception' => $e]);
                throw new CouldNotSaveException(__('Compensation for order %1 was not written; the exception log has the cause.', $orderId), $e);
            }
            $this->notes->noteOutcome($orderId, $outcome);
            if ($outcome->isRefused() || $outcome->kind === Outcome::ALREADY_BALANCED) {
                // nothing was written: Magento's loop prints the reason and exits non-zero
                throw new CouldNotSaveException(__('Compensation for order %1: %2', $orderId, $outcome->describe()));
            }
        }
    }

    /**
     * The order a manual compensation names, when this module holds an
     * obligation for it; null for every other row.
     */
    private function coordinatedOrderId(ReservationInterface $reservation): ?int
    {
        $metadata = $reservation->getMetadata();
        if (!is_string($metadata) || $metadata === '') {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($metadata);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data)
            || ($data['event_type'] ?? '') !== self::EVENT_MANUAL_COMPENSATION
            || ($data['object_type'] ?? '') !== self::OBJECT_TYPE_ORDER
        ) {
            return null;
        }
        $orderId = (int) ($data['object_id'] ?? 0);
        if ($orderId <= 0 || !$this->obligations->hasRowsFor($orderId)) {
            return null;
        }
        return $orderId;
    }
}
