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
use Magento\InventorySalesApi\Api\Data\ItemToSellInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventExtensionFactory;
use Magento\InventorySalesApi\Api\Data\SalesEventInterface;
use Magento\InventorySalesApi\Api\Data\SalesEventInterfaceFactory;
use Magento\InventorySalesApi\Api\PlaceReservationsForSalesEventInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * The order_placed reservation, through Inventory's sales-event service
 * (the one Adobe's documentation names for a business event), with a
 * sales event of type order_placed for the order and the website's sales
 * channel. One item to sell per sku, for what the order still owes on it
 * by Magento's own rule: a shipment, a cancellation or a partial
 * compensation that came first is on the rows already and is not placed
 * again, so a registration retried after a repair by hand ends balanced.
 * A sku reserved past what the order owes is nothing a placement can
 * fix: that row is refused for a compensation of the surplus, while the
 * order's other rows are placed in the same run.
 */
class InventoryHandler implements HandlerInterface
{
    public const EVENT_ORDER_PLACED = SalesEventInterface::EVENT_ORDER_PLACED;

    public function __construct(
        private readonly PlaceReservationsForSalesEventInterface $placeReservations,
        private readonly ItemToSellInterfaceFactory $itemFactory,
        private readonly SalesChannelInterfaceFactory $salesChannelFactory,
        private readonly SalesEventInterfaceFactory $salesEventFactory,
        private readonly SalesEventExtensionFactory $salesEventExtensionFactory,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly RemainingInconsistency $remaining
    ) {
    }

    /**
     * Places what the owed skus still owe through Inventory's sales-event service, in one call, and answers for each row.
     */
    public function register(OrderInterface $order, array $owed): array
    {
        $outcomes = [];
        $items = [];
        foreach ($owed as $obligation) {
            if ($obligation->qtyOrdered <= 0) {
                $outcomes[] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            if ($obligation->stockId === null) {
                $outcomes[] = new RowOutcome($obligation, RowOutcome::REFUSED, reason: (string) __(
                    'The website was assigned to no stock when the order was recorded; there is no stock to read the remainder on.'
                ));
                continue;
            }
            // what is ordered and neither canceled, refunded nor shipped,
            // less the rows already there on the stock the order was
            // recorded on; PlaceReservationsForSalesEvent then reads each
            // sku's type from the catalog and leaves out what holds no
            // stock of its own, as it does for the checkout
            $remaining = $this->remaining->forOrderSku($order, $obligation->sku, $obligation->stockId)['remaining'];
            if (abs($remaining) < Obligation::TOLERANCE) {
                $outcomes[] = new RowOutcome($obligation, RowOutcome::BALANCED);
                continue;
            }
            if ($remaining > 0) {
                $outcomes[] = new RowOutcome($obligation, RowOutcome::REFUSED, reason: (string) __(
                    'Sku %1 is reserved %2 past what the order owes on stock %3; nothing to place, a compensation of %2 repairs it.',
                    $obligation->sku,
                    sprintf('%+.4f', $remaining),
                    $obligation->stockId
                ), remainders: [$obligation->stockId => $remaining]);
                continue;
            }
            $items[] = $this->itemFactory->create(['sku' => $obligation->sku, 'qty' => $remaining]);
            $outcomes[] = new RowOutcome($obligation, RowOutcome::PLACED, $remaining);
        }
        if ($items === []) {
            return $outcomes;
        }
        $websiteId = (int) $order->getStore()->getWebsiteId();
        $websiteCode = (string) $this->websiteRepository->getById($websiteId)->getCode();
        $salesChannel = $this->salesChannelFactory->create([
            'data' => ['type' => SalesChannelInterface::TYPE_WEBSITE, 'code' => $websiteCode],
        ]);
        $salesEvent = $this->salesEventFactory->create([
            'type' => self::EVENT_ORDER_PLACED,
            'objectType' => SalesEventInterface::OBJECT_TYPE_ORDER,
            'objectId' => (string) $order->getEntityId(),
        ]);
        $salesEvent->setExtensionAttributes($this->salesEventExtensionFactory->create([
            'data' => ['objectIncrementId' => (string) $order->getIncrementId()],
        ]));
        $this->placeReservations->execute($items, $salesChannel, $salesEvent);
        return $outcomes;
    }
}
