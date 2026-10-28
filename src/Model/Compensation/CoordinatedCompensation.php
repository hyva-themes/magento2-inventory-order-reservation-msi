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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * A manual compensation that names an order, under that order's lock.
 * The named sku's row is read first: a refused or done row is judged as
 * it is; an owed row has the order's registration run first, and is
 * judged after it, or refused as pending when it is still owed then. So
 * an unrelated row that cannot register never blocks a repair, and a
 * failed registration reaches the caller with nothing judged.
 *
 * The request is held against the order's persisted obligation before
 * anything is calculated: a sku the order owes nothing on, an obligation
 * or settled on the legacy stock item (no stock to compensate), is
 * refused outright. A row whose product the catalog renamed is found
 * under its new sku and judged there once Magento Inventory moved the
 * order's reservations to it, and refused until then (Rename).
 * Then, on the stock the request names: already balanced, nothing is
 * written; the right amount, or part of it toward balance, is written
 * with the remainder reported; the wrong direction or more than remains
 * is refused with the current discrepancy. A refused row is settled when
 * nothing remains on any stock that matters to it. A refusal never
 * touches the registration, which is complete on its own by then.
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
        $sku = (string) $reservation->getSku();
        $stockId = (int) $reservation->getStockId();
        $requested = (float) $reservation->getQuantity();
        // the named row first: a refused or done row is judged as it is, so
        // an unrelated row that cannot register never blocks a repair
        $obligation = $this->obligationFor($orderId, $sku);
        if ($obligation === null) {
            return $this->refuse($sku, $stockId, $requested, false, 'the order owes nothing on this sku');
        }
        $ran = false;
        if ($obligation->isOwed()) {
            // the module's own to place first; a failure here rolls the
            // transaction back and reaches the caller, nothing judged
            $ran = $this->registration->registerLocked($orderId);
            $obligation = $this->obligationFor($orderId, $sku) ?? $obligation;
            if ($obligation->isOwed()) {
                return $this->refuse($sku, $stockId, $requested, $ran, 'its registration is this module\'s and has not run');
            }
        }
        if ($obligation->mode !== Obligation::MODE_INVENTORY || $obligation->stockId === null) {
            return $this->refuse($sku, $stockId, $requested, $ran, 'the order\'s stock side is the legacy stock item, not a Magento Inventory stock');
        }
        $order = $this->orderRepository->get($orderId);
        // a renamed row is judged under the catalog's sku once Magento
        // Inventory moved the order's reservations there, and refused while
        // they are split or kept under the recorded sku, as the registration
        // refuses it
        $rename = $this->remaining->rename($order, $obligation);
        if ($rename !== null) {
            if (!$rename->isFollowed()) {
                return $this->refuse($sku, $stockId, $requested, $ran, $this->renameRefusal($rename, $orderId));
            }
            if ($sku !== $rename->currentSku) {
                return $this->refuse($sku, $stockId, $requested, $ran, sprintf(
                    'the product is %1$s in the catalog now and Magento Inventory moved the order\'s reservations to %1$s; a compensation under %1$s repairs the row',
                    $rename->currentSku
                ));
            }
        }
        if ($this->remaining->hasAmbiguousRows($order, $sku)) {
            // refused by the registration for the same reason: a row that may
            // be this order's is counted for none, so the numbers below are not
            // the order's
            return $this->refuse($sku, $stockId, $requested, $ran, sprintf(
                'a reservation names only the order number %s, which orders of several stores carry; inventory:order-reservation:settle %d closes the row once the stock is corrected by hand',
                (string) $order->getIncrementId(),
                $orderId
            ));
        }
        // judged on the stock the request names: the order's expectation on
        // the stock the website sells from now, zero elsewhere, less the rows
        // on that stock; a stock the order expects nothing on and holds no
        // row on is balanced, which keeps a compensation off a stock the
        // order has nothing to do with
        $remaining = $this->remaining->forOrderSku($order, $sku, $stockId, $obligation->productId)['remaining'];
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
        if ($obligation->isRefused() && $this->balancedEverywhere($order, $sku, $obligation->stockId, $obligation->productId)) {
            // the registration refused this row; this compensation settled
            // it, nothing remaining on any stock that matters to it. The
            // order's other skus keep their own state until each is balanced,
            // so one repair never hides another discrepancy.
            $this->obligations->markRowDone($orderId, $obligation->sku, Obligation::SETTLED_BY_COMPENSATION);
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
     * Whether nothing remains for the row on any stock that matters to it, read again after the write.
     */
    private function balancedEverywhere(OrderInterface $order, string $sku, int $recordedStockId, ?int $productId): bool
    {
        foreach ($this->remaining->remaindersForRow($order, $sku, $recordedStockId, $productId) as $remaining) {
            if (abs($remaining) >= Obligation::TOLERANCE) {
                return false;
            }
        }
        return true;
    }

    /**
     * A refusal: nothing written, the remaining discrepancy reported as it stands.
     */
    private function refuse(string $sku, int $stockId, float $requested, bool $ran, string $reason, float $remaining = 0.0): Outcome
    {
        return new Outcome(Outcome::REFUSED, $sku, $stockId, $requested, $remaining, $remaining, $ran, $reason);
    }

    /**
     * Why a compensation for a renamed row is refused, and the step that moves it on.
     */
    private function renameRefusal(Rename $rename, int $orderId): string
    {
        if ($rename->state === Rename::PENDING) {
            return sprintf(
                'the product is %2$s in the catalog now and the order\'s reservations are still under %1$s; Magento Inventory moves them to %2$s '
                . 'through its inventory.reservations.update consumer: run bin/magento queue:consumers:start inventory.reservations.update, '
                . 'then repeat the compensation under %2$s. Should they stay under %1$s after that, the product was renamed while Synchronize with Catalog was off, '
                . 'and inventory:order-reservation:settle %3$d closes the row',
                $rename->recordedSku,
                $rename->currentSku,
                $orderId
            );
        }
        return sprintf(
            'the product is %2$s in the catalog now, and with Synchronize with Catalog off Magento Inventory does not move reservations to a renamed sku; '
            . 'turn it on (Stores > Configuration > Catalog > Inventory > Stock Options), then a compensation under %2$s is judged there '
            . 'when no reservation of the order is left under %1$s; inventory:order-reservation:settle %3$d closes the row otherwise',
            $rename->recordedSku,
            $rename->currentSku,
            $orderId
        );
    }

    /**
     * The order's persisted obligation for the sku, the row the request is held against: the row recorded under the sku, else a row whose product the catalog renamed to it since.
     */
    private function obligationFor(int $orderId, string $sku): ?Obligation
    {
        $rows = $this->obligations->forOrder($orderId);
        foreach ($rows as $obligation) {
            if ($obligation->sku === $sku) {
                return $obligation;
            }
        }
        foreach ($rows as $obligation) {
            if ($this->remaining->renamedTo($obligation) === $sku) {
                return $obligation;
            }
        }
        return null;
    }
}
