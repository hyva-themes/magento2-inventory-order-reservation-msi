<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

use Hyva\InventoryOrderReservation\Model\ProductIdentities;
use Hyva\InventoryOrderReservation\Model\ResourceModel\WebsiteStock;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * Magento's own inconsistency rule, read live for one order and sku: an
 * order in a non-final state should hold reservations summing to minus
 * (ordered - canceled - refunded - shipped, invoiced for a virtual line);
 * a complete, closed or canceled order should sum to zero. The expectation
 * counts on the stock the order's website sells from now, read from the
 * sales-channel table as the core's recorder reads it, and is zero on any
 * other stock; the rows on
 * record are read per stock. What remains on a stock is the expected sum
 * there less the rows there, the amount a compensation may still add on
 * it. A row of the module's record is balanced only when nothing remains
 * on any stock that matters to it: the stock it was recorded on, the
 * stock the website sells from now, and every stock holding a row for
 * the order and sku.
 *
 * A line is counted under the product that holds its stock, as Magento's
 * placement resolves it (GetSkuFromOrderItem) and as the core's recorder
 * recorded the row: the catalog's sku and type for the line's product id.
 * A line with custom options carries a sku of its own ("SHIRT-engr"), a
 * grouped product's child carries the type "grouped", and a composite
 * parent line carries its child's sku; read by the product, each lands
 * where its reservation does. A line whose product the catalog no longer
 * holds keeps its own sku and type, with "grouped" counted as Magento's
 * listing counts it.
 */
class RemainingInconsistency
{
    private const GROUPED = 'grouped';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $managementAllowed,
        private readonly GetStockItemConfigurationInterface $stockItemConfiguration,
        private readonly WebsiteStock $websiteStock,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly ProductIdentities $products
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
     * What remains per stock for one row of the record: on the stock the row was recorded on, the stock the website sells from now, and every stock holding a row for the order and sku.
     *
     * @return array<int, float> stock id => remaining, ascending by stock id
     */
    public function remaindersForRow(OrderInterface $order, string $sku, int $recordedStockId): array
    {
        $stocks = array_unique(array_merge(
            [$recordedStockId, $this->currentStockId($order)],
            $this->stocksWithRows((int) $order->getEntityId(), (string) $order->getIncrementId(), $sku)
        ));
        sort($stocks);
        $remainders = [];
        foreach ($stocks as $stockId) {
            $remainders[$stockId] = $this->forOrderSku($order, $sku, $stockId)['remaining'];
        }
        return $remainders;
    }

    /**
     * The stock the order's website sells from now, which is where Magento counts the order's expectation; zero when the website is assigned to none.
     */
    public function currentStockId(OrderInterface $order): int
    {
        $code = (string) $this->websiteRepository->getById((int) $order->getStore()->getWebsiteId())->getCode();
        return (int) ($this->websiteStock->idForWebsiteCode($code) ?? 0);
    }

    /**
     * The reservation sum Magento expects for the sku on this order on a stock: zero in a final state and on any stock but the one the website sells from now, else minus what is ordered and neither canceled, refunded nor shipped (invoiced for a virtual line), for the product types Inventory manages.
     */
    public function expectedSum(OrderInterface $order, string $sku, ?int $stockId = null): float
    {
        if (in_array((string) $order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED], true)) {
            return 0.0;
        }
        if ($stockId !== null && $stockId !== $this->currentStockId($order)) {
            return 0.0;
        }
        // a product whose stock is not managed is left out of Magento's own
        // listing (FilterManagedStockProducts)
        if (!$this->isManaged($sku, $stockId)) {
            return 0.0;
        }
        $items = $order->getItems();
        $identities = $this->products->byProductIds(array_map(static fn (OrderItemInterface $item): int => (int) $item->getProductId(), $items));
        $qty = 0.0;
        foreach ($items as $item) {
            $identity = $identities[(int) $item->getProductId()] ?? null;
            if ($identity !== null) {
                if ($identity['sku'] !== $sku || ProductIdentities::isComposite($identity['type'])) {
                    continue;
                }
                $type = $identity['type'];
            } else {
                if ((string) $item->getSku() !== $sku) {
                    continue;
                }
                $type = (string) $item->getProductType();
            }
            // Magento's listing counts a grouped product's child under the line type "grouped"
            if ($type !== '' && $type !== self::GROUPED && !$this->managementAllowed->execute($type)) {
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
     * The stocks holding a row for the order and sku, whichever spelling of the order's id the writer used.
     *
     * @return int[]
     */
    private function stocksWithRows(int $orderId, string $incrementId, string $sku): array
    {
        $connection = $this->resourceConnection->getConnection();
        // the reservation table's only index leads with the stock id, so the
        // stocks are named: a filter on the sku alone reads the whole table
        $stockIds = $connection->fetchCol(
            $connection->select()->from($this->resourceConnection->getTableName('inventory_stock'), ['stock_id'])
        );
        if ($stockIds === []) {
            return [];
        }
        $select = $connection->select()
            ->distinct()
            ->from($this->resourceConnection->getTableName('inventory_reservation'), ['stock_id'])
            ->where('stock_id IN (?)', array_map('intval', $stockIds))
            ->where('sku = ?', $sku)
            ->where(
                "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_id')) = " . $connection->quote((string) $orderId)
                . " OR JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = " . $connection->quote($incrementId)
            );
        return array_map('intval', $connection->fetchCol($select));
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
