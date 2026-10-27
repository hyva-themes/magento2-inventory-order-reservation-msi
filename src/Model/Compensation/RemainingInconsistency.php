<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

/**
 * Magento's own inconsistency rule, read live for one order and sku: an
 * order in a non-final state should hold reservations summing to minus
 * (ordered - canceled - refunded - shipped, invoiced for a virtual line);
 * a complete, closed or canceled order should sum to zero. What remains
 * is the expected sum less the rows on record, the amount a compensation
 * may still add.
 */
class RemainingInconsistency
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $managementAllowed,
        private readonly GetStockItemConfigurationInterface $stockItemConfiguration
    ) {
    }

    /** @return array{expected: float, actual: float, remaining: float}
     */
    public function forOrderSku(OrderInterface $order, string $sku, int $stockId): array
    {
        $expected = $this->expectedSum($order, $sku, $stockId);
        $actual = $this->actualSum((int) $order->getEntityId(), (string) $order->getIncrementId(), $sku, $stockId);
        return ['expected' => $expected, 'actual' => $actual, 'remaining' => round($expected - $actual, 4)];
    }

    /**
     * The reservation sum Magento expects for the sku on this order: zero in a final state, else minus what is ordered and neither canceled, refunded nor shipped (invoiced for a virtual line), for the product types Inventory manages.
     */
    public function expectedSum(OrderInterface $order, string $sku, ?int $stockId = null): float
    {
        if (in_array((string) $order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED], true)) {
            return 0.0;
        }
        // a product whose stock is not managed is left out of Magento's own
        // listing (FilterManagedStockProducts)
        if (!$this->isManaged($sku, $stockId)) {
            return 0.0;
        }
        $qty = 0.0;
        foreach ($order->getItems() as $item) {
            if ((string) $item->getSku() !== $sku) {
                continue;
            }
            $type = (string) $item->getProductType();
            if ($type !== '' && !$this->managementAllowed->execute($type)) {
                continue;
            }
            $progress = $this->progress($item);
            $line = (float) $item->getQtyOrdered() - $progress['canceled'] - $progress['refunded'];
            $line -= $item->getIsVirtual() ? $progress['invoiced'] : $progress['shipped'];
            $qty += $line;
        }
        return -$qty;
    }

    /**
     * An item's progress as Magento's inconsistency calculator reads it (GetOrderItemsDataForOrdersInNotFinalState): a child of a parent that ships as one unit, a configurable's child or a bundle's unless the bundle ships separately, carries no progress of its own, so the parent's canceled, invoiced, shipped and refunded quantities are projected onto it in proportion to the quantities ordered.
     * @return array<string, float>
     */
    private function progress(OrderItemInterface $item): array
    {
        $parent = $item->getParentItem();
        $parentOrdered = $parent ? (float) $parent->getQtyOrdered() : 0.0;
        if ($parent && $parentOrdered > 0 && !$this->shipsSeparately($parent)) {
            $unit = (float) $item->getQtyOrdered() / $parentOrdered;
            return [
                'canceled' => $unit * (float) $parent->getQtyCanceled(),
                'invoiced' => $unit * (float) $parent->getQtyInvoiced(),
                'shipped' => $unit * (float) $parent->getQtyShipped(),
                'refunded' => $unit * (float) $parent->getQtyRefunded(),
            ];
        }
        return [
            'canceled' => (float) $item->getQtyCanceled(),
            'invoiced' => (float) $item->getQtyInvoiced(),
            'shipped' => (float) $item->getQtyShipped(),
            'refunded' => (float) $item->getQtyRefunded(),
        ];
    }

    private function isManaged(string $sku, ?int $stockId): bool
    {
        if ($stockId === null) {
            return true;
        }
        try {
            return (bool) $this->stockItemConfiguration->execute($sku, $stockId)->isManageStock();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Whether a bundle parent ships its children separately, in which case each child carries its own progress.
     */
    private function shipsSeparately(OrderItemInterface $parent): bool
    {
        $options = $parent instanceof Order\Item ? $parent->getProductOptions() : null;
        return is_array($options)
            && isset($options['shipment_type'])
            && (int) $options['shipment_type'] === AbstractType::SHIPMENT_SEPARATELY;
    }

    /**
     * The sum of the reservation rows on record for the order and sku on the stock, whichever spelling of the order's id the writer used.
     */
    private function actualSum(int $orderId, string $incrementId, string $sku, int $stockId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_reservation');
        // the writers spell the order's id as a string or a number, and some
        // name the increment id; read as JSON, so an id that is a prefix of
        // another, or holds a character the JSON escapes, matches itself only
        $select = $connection->select()
            ->from($table, ['total' => 'COALESCE(SUM(quantity), 0)'])
            ->where('sku = ?', $sku)
            ->where('stock_id = ?', $stockId)
            ->where(
                "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_id')) = " . $connection->quote((string) $orderId)
                . " OR JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = " . $connection->quote($incrementId)
            );
        return (float) $connection->fetchOne($select);
    }
}
