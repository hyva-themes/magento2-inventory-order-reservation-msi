<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Hyva\InventoryOrderReservation\Model\StockRegistration;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * A manual compensation that names an order, under that order's lock:
 * the order's owed registration completes first, then the request is
 * read against what the order still owes by Magento's own rule.
 *
 * The request is held against the order's persisted obligation before
 * anything is calculated: a sku the order owes nothing on, an obligation
 * settled on the legacy stock item (no stock to compensate), or a stock
 * other than the one the obligation names, is refused outright, so a
 * compensation can never reach a stock the order is not registered on.
 * Then, on the obligation's stock: already balanced, nothing is written;
 * the right amount, or part of it toward balance, is written with the
 * remainder reported; the wrong direction or more than remains is
 * refused with the current discrepancy. A refusal never touches the
 * registration, which is complete on its own by then.
 */
class CoordinatedCompensation
{
    /** True while the service itself writes, so its writer plugin passes the row through. */
    private bool $writing = false;

    public function __construct(
        private readonly OrderMutexInterface $orderMutex,
        private readonly StockRegistration $registration,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RemainingInconsistency $remaining,
        private readonly AppendReservationsInterface $writer,
        private readonly ObligationResource $obligations
    ) {
    }

    /**
     * Whether the service is appending its own validated row right now, so the writer plugin lets it through.
     */
    public function isWriting(): bool
    {
        return $this->writing;
    }

    /**
     * @throws LocalizedException when the order's registration, the write or the settlement cannot complete; nothing is written then
     */
    public function apply(int $orderId, ReservationInterface $reservation): Outcome
    {
        return $this->orderMutex->execute($orderId, $this->applyLocked(...), [$orderId, $reservation]);
    }

    private function applyLocked(int $orderId, ReservationInterface $reservation): Outcome
    {
        $ran = $this->registration->registerLocked($orderId);
        $sku = (string) $reservation->getSku();
        $stockId = (int) $reservation->getStockId();
        $requested = (float) $reservation->getQuantity();
        $obligation = $this->obligationFor($orderId, $sku);
        if ($obligation === null) {
            return $this->refuse($sku, $stockId, $requested, $ran, 'the order owes nothing on this sku');
        }
        if ($obligation->mode !== Obligation::MODE_INVENTORY || $obligation->stockId === null) {
            return $this->refuse($sku, $stockId, $requested, $ran, 'the order\'s stock side is the legacy stock item, not a Magento Inventory stock');
        }
        // what the order still owes on the sku by Magento's own rule, on the stock it is registered on
        $order = $this->orderRepository->get($orderId);
        $remaining = $this->remaining->forOrderSku($order, $sku, $obligation->stockId)['remaining'];
        if ($obligation->stockId !== $stockId) {
            return $this->refuse(
                $sku,
                $stockId,
                $requested,
                $ran,
                sprintf('stock %d named, the order is registered on stock %d', $stockId, $obligation->stockId),
                $remaining
            );
        }
        if (abs($remaining) < Obligation::TOLERANCE) {
            return new Outcome(Outcome::ALREADY_BALANCED, $sku, $stockId, $requested, 0.0, 0.0, $ran);
        }
        if (abs($requested) < Obligation::TOLERANCE || ($requested < 0) !== ($remaining < 0)) {
            return $this->refuse($sku, $stockId, $requested, $ran, 'wrong direction', $remaining);
        }
        if (abs($requested) > abs($remaining) + Obligation::TOLERANCE) {
            return $this->refuse($sku, $stockId, $requested, $ran, 'more than remains', $remaining);
        }
        if (!$this->obligations->isOneConnection()) {
            throw new LocalizedException(__(
                'A coordinated compensation needs the sales tables and the inventory tables on one database connection; this store keeps them apart.'
            ));
        }
        // under the order lock, inside its transaction: the row and the
        // settlement below land together or not at all
        $this->writing = true;
        try {
            $this->writer->execute([$reservation]);
        } finally {
            $this->writing = false;
        }
        $after = round($remaining - $requested, 4);
        if ($obligation->isRefused() && abs($after) < Obligation::TOLERANCE) {
            // the registration gave the order up; this compensation settled
            // this sku. The order's other skus keep their own state until
            // each is balanced, so one repair never hides another discrepancy.
            $this->obligations->markRowDone($orderId, $sku, Obligation::SETTLED_BY_COMPENSATION);
        }
        return new Outcome(
            abs($after) < Obligation::TOLERANCE ? Outcome::APPLIED : Outcome::PARTIAL,
            $sku,
            $stockId,
            $requested,
            $remaining,
            $after,
            $ran
        );
    }

    /**
     * A refusal: nothing written, the remaining discrepancy reported as it stands.
     */
    private function refuse(string $sku, int $stockId, float $requested, bool $ran, string $reason, float $remaining = 0.0): Outcome
    {
        return new Outcome(Outcome::REFUSED, $sku, $stockId, $requested, $remaining, $remaining, $ran, $reason);
    }

    /**
     * The order's persisted obligation for the sku, the row the request is held against.
     */
    private function obligationFor(int $orderId, string $sku): ?Obligation
    {
        foreach ($this->obligations->forOrder($orderId) as $obligation) {
            if ($obligation->sku === $sku) {
                return $obligation;
            }
        }
        return null;
    }
}
