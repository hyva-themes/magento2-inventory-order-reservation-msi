<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\Handler\RowOutcome;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventoryReservationsApi\Model\AppendReservationsInterface;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\InventorySales\Model\SalesEventToArrayConverter;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The order_placed reservation on the stock the order was recorded on.
 * Magento's own placement service resolves the stock from the website's
 * sales channel as it is today, so it cannot write on the recorded stock
 * after the website moved; this handler builds the rows that service
 * would build and appends them itself: one reservation per sku through
 * the reservation builder, with the stock id the obligation carries and
 * the metadata Magento writes (the sales-event converter on an event of
 * type order_placed for the order), Magento's product-type filter kept as
 * the service applies it, and Inventory's writer as the writer, so its
 * own plugin on it still drops rows for products whose stock is not
 * managed. The rows are indistinguishable from Magento's placement but
 * for the stock they name; the website's mapping is never touched.
 *
 * One item per sku, for what the order still owes on it by Magento's own
 * rule: a shipment, a cancellation or a partial compensation that came
 * first is on the rows already and is not placed again. Completion is
 * decided per row over every stock that matters to it (the recorded
 * stock, the stock the website sells from now, every stock holding a row
 * for the order and sku): nothing remains anywhere, the row is balanced;
 * the one thing remaining is a lack on the recorded stock, it is placed
 * there; anything remains elsewhere, or a surplus on the recorded stock,
 * the row is refused naming each stock and its remainder, since the
 * writer repairs the recorded stock only and only downward, and a
 * compensation on each stock named repairs it. The order's other rows
 * are placed in the same run.
 */
class InventoryHandler implements HandlerInterface
{
    public const EVENT_ORDER_PLACED = SalesEventInterface::EVENT_ORDER_PLACED;

    public function __construct(
        private readonly ReservationBuilderInterface $reservationBuilder,
        private readonly AppendReservationsInterface $appendReservations,
        private readonly SalesEventInterfaceFactory $salesEventFactory,
        private readonly SalesEventExtensionFactory $salesEventExtensionFactory,
        private readonly SalesEventToArrayConverter $salesEventToArrayConverter,
        private readonly SerializerInterface $serializer,
        private readonly GetProductTypesBySkusInterface $getProductTypesBySkus,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        private readonly RemainingInconsistency $remaining
    ) {
    }

    /**
     * Places what the owed skus still owe on the stocks their rows name, in one append, and answers for each row.
     */
    public function register(OrderInterface $order, array $owed): array
    {
        // one answer per row, in the rows' order; what is to be placed is decided first and written in one append
        $outcomes = [];
        $toPlace = [];
        foreach ($owed as $index => $obligation) {
            if ($obligation->qtyOrdered <= 0) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            if ($obligation->stockId === null) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::REFUSED, reason: (string) __(
                    'The website was assigned to no stock when the order was recorded; there is no stock to read the remainder on.'
                ));
                continue;
            }
            // what remains on every stock that matters to the row: the recorded
            // stock, the stock the website sells from now, every stock with a row
            $remainders = $this->remaining->remaindersForRow($order, $obligation->sku, $obligation->stockId);
            $nonZero = array_filter($remainders, static fn (float $remaining): bool => abs($remaining) >= Obligation::TOLERANCE);
            if ($nonZero === []) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            $onRecorded = $remainders[$obligation->stockId];
            if (count($nonZero) === 1 && isset($nonZero[$obligation->stockId]) && $onRecorded < 0) {
                $toPlace[$index] = [$obligation, $onRecorded];
                continue;
            }
            $outcomes[$index] = new RowOutcome(
                $obligation,
                RowOutcome::REFUSED,
                reason: $this->refusal($obligation, $nonZero, $this->remaining->currentStockId($order)),
                remainders: $nonZero
            );
        }
        if ($toPlace === []) {
            ksort($outcomes);
            return array_values($outcomes);
        }
        // Magento's own filter, as its placement service applies it: a sku
        // the catalog does not hold is written, a sku whose type allows no
        // source-item management is not, and its row is balanced
        $types = $this->getProductTypesBySkus->execute(array_values(array_map(static fn (array $pair): string => $pair[0]->sku, $toPlace)));
        $metadata = $this->serializer->serialize($this->salesEventToArrayConverter->execute($this->salesEvent($order)));
        $rows = [];
        foreach ($toPlace as $index => [$obligation, $remaining]) {
            $type = $types[$obligation->sku] ?? null;
            if ($type !== null && !$this->isSourceItemManagementAllowedForProductType->execute((string) $type)) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            $rows[] = $this->reservationBuilder
                ->setSku($obligation->sku)
                ->setQuantity($remaining)
                ->setStockId($obligation->stockId)
                ->setMetadata($metadata)
                ->build();
            $outcomes[$index] = new RowOutcome($obligation, RowOutcome::PLACED, $remaining);
        }
        if ($rows !== []) {
            $this->appendReservations->execute($rows);
        }
        ksort($outcomes);
        return array_values($outcomes);
    }

    /**
     * Why a row cannot be placed: each stock with a remainder and the compensation that repairs it, and the move when the website sells from another stock than the one recorded.
     *
     * @param array<int, float> $nonZero stock id => remaining
     */
    private function refusal(Obligation $obligation, array $nonZero, int $currentStockId): string
    {
        $parts = [];
        foreach ($nonZero as $stockId => $remaining) {
            $parts[] = $remaining < 0
                ? (string) __('stock %1 lacks %2, a compensation of %2 on it repairs that', $stockId, sprintf('%+.4f', $remaining))
                : (string) __('stock %1 holds %2 past what the order owes, a compensation of %2 on it repairs that', $stockId, sprintf('%+.4f', $remaining));
        }
        $moved = $currentStockId !== $obligation->stockId
            ? (string) __('the website sells from stock %1 now and the order was recorded on stock %2; ', $currentStockId, $obligation->stockId)
            : '';
        return (string) __('Sku %1: %2%3. Nothing is placed.', $obligation->sku, $moved, implode('; ', $parts));
    }

    /**
     * The sales event Magento's placement writes for an order: type order_placed, the order's id and increment id.
     */
    private function salesEvent(OrderInterface $order): SalesEventInterface
    {
        $salesEvent = $this->salesEventFactory->create([
            'type' => self::EVENT_ORDER_PLACED,
            'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
            'objectId' => (string) $order->getEntityId(),
        ]);
        $salesEvent->setExtensionAttributes($this->salesEventExtensionFactory->create([
            'data' => ['objectIncrementId' => (string) $order->getIncrementId()],
        ]));
        return $salesEvent;
    }
}
