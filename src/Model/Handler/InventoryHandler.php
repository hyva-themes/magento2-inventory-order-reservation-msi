<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Handler;

use Hyva\InventoryOrderReservation\Model\Handler\HandlerInterface;
use Hyva\InventoryOrderReservation\Model\Handler\RepairNeededException;
use Hyva\InventoryOrderReservation\Model\Obligation;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Framework\Exception\LocalizedException;
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
 * fix: the order is given up on for a compensation of the surplus.
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
     * Places what the owed skus still owe through Inventory's sales-event service; nothing when every sku is balanced.
     * @throws LocalizedException when an obligation names no stock to read the remainder on
     * @throws RepairNeededException when a sku is reserved past what the order owes
     */
    public function register(OrderInterface $order, array $owed): void
    {
        $items = [];
        foreach ($owed as $obligation) {
            if ($obligation->qtyOrdered <= 0) {
                continue;
            }
            if ($obligation->stockId === null) {
                throw new LocalizedException(__('The obligation for sku %1 names no stock to read its remainder on.', $obligation->sku));
            }
            // what is ordered and neither canceled, refunded nor shipped,
            // less the rows already there on the stock the order was
            // recorded on; PlaceReservationsForSalesEvent then reads each
            // sku's type from the catalog and leaves out what holds no
            // stock of its own, as it does for the checkout
            $remaining = $this->remaining->forOrderSku($order, $obligation->sku, $obligation->stockId)['remaining'];
            if (abs($remaining) < Obligation::TOLERANCE) {
                continue;
            }
            if ($remaining > 0) {
                throw new RepairNeededException(__(
                    'Sku %1 is reserved %2 past what the order owes on stock %3; nothing to place, a compensation of %2 repairs it.',
                    $obligation->sku,
                    sprintf('%+.4f', $remaining),
                    $obligation->stockId
                ));
            }
            $items[] = $this->itemFactory->create(['sku' => $obligation->sku, 'qty' => $remaining]);
        }
        if ($items === []) {
            return;
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
    }
}
