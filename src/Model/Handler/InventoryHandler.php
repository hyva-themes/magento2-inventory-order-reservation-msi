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
 * first is on the rows already and is not placed again. A sku reserved
 * past what the order owes is nothing a placement can fix: that row is
 * refused for a compensation of the surplus, while the order's other rows
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
            // what is ordered and neither canceled, refunded nor shipped,
            // less the rows already there on the stock the order was recorded on
            $remaining = $this->remaining->forOrderSku($order, $obligation->sku, $obligation->stockId)['remaining'];
            if (abs($remaining) < Obligation::TOLERANCE) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            if ($remaining > 0) {
                $outcomes[$index] = new RowOutcome($obligation, RowOutcome::REFUSED, reason: (string) __(
                    'Sku %1 is reserved %2 past what the order owes on stock %3; nothing to place, a compensation of %2 repairs it.',
                    $obligation->sku,
                    sprintf('%+.4f', $remaining),
                    $obligation->stockId
                ), remainders: [$obligation->stockId => $remaining]);
                continue;
            }
            $toPlace[$index] = [$obligation, $remaining];
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
