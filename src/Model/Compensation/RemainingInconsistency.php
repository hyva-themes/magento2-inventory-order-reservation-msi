<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

use Hyva\InventoryOrderReservation\Model\Obligation;
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
 * listing counts it. Given the product id a row was recorded for, the
 * lines are those of that product, so a sku the catalog renamed since
 * still finds them.
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
    public function forOrderSku(OrderInterface $order, string $sku, int $stockId, ?int $productId = null): array
    {
        $expected = $this->expectedSum($order, $sku, $stockId, $productId);
        $actual = $this->actualSum((int) $order->getEntityId(), (string) $order->getIncrementId(), $sku, $stockId);
        return ['expected' => $expected, 'actual' => $actual, 'remaining' => round($expected - $actual, 4)];
    }

    /**
     * The sku the catalog gives the row's product now, when it differs from the sku the row was recorded under; null when it does not, or the row names no product the catalog holds.
     *
     * A renamed row has two candidate reservation keys: the recorded sku,
     * which Magento's shipment no longer deducts, and the new one, which the
     * record does not name. Neither the registration nor a compensation
     * picks one; the row is closed by hand (inventory:order-reservation:settle).
     */
    public function renamedTo(Obligation $row): ?string
    {
        if ($row->productId === null) {
            return null;
        }
        $current = $this->products->byProductIds([$row->productId])[$row->productId]['sku'] ?? null;
        return $current !== null && $current !== $row->sku ? $current : null;
    }

    /**
     * What remains per stock for one row of the record: on the stock the row was recorded on, the stock the website sells from now, and every stock holding a row for the order and sku.
     *
     * @return array<int, float> stock id => remaining, ascending by stock id
     */
    public function remaindersForRow(OrderInterface $order, string $sku, int $recordedStockId, ?int $productId = null): array
    {
        $stocks = array_unique(array_merge(
            [$recordedStockId, $this->currentStockId($order)],
            $this->stocksWithRows((int) $order->getEntityId(), (string) $order->getIncrementId(), $sku)
        ));
        sort($stocks);
        $remainders = [];
        foreach ($stocks as $stockId) {
            $remainders[$stockId] = $this->forOrderSku($order, $sku, $stockId, $productId)['remaining'];
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
    public function expectedSum(OrderInterface $order, string $sku, ?int $stockId = null, ?int $productId = null): float
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
            if ($productId !== null) {
                // the row's own product, whatever the catalog calls it today
                if ((int) $item->getProductId() !== $productId
                    || ($identity !== null && ProductIdentities::isComposite($identity['type']))
                ) {
                    continue;
                }
                $type = $identity['type'] ?? (string) $item->getProductType();
            } elseif ($identity !== null) {
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
     * The stocks holding a row for the order and sku (rowsOfOrder()).
     *
     * @return int[]
     */
    private function stocksWithRows(int $orderId, string $incrementId, string $sku): array
    {
        $connection = $this->resourceConnection->getConnection();
        $stockIds = $this->stockIdsWithRows();
        if ($stockIds === []) {
            return [];
        }
        $select = $connection->select()
            ->distinct()
            ->from($this->resourceConnection->getTableName('inventory_reservation'), ['stock_id'])
            ->where('stock_id IN (?)', $stockIds)
            ->where('sku = ?', $sku)
            ->where($this->rowsOfOrder($orderId, $incrementId));
        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * The sum of the reservation rows on record for the order and sku on the stock (rowsOfOrder()).
     */
    private function actualSum(int $orderId, string $incrementId, string $sku, int $stockId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_reservation');
        $select = $connection->select()
            ->from($table, ['total' => 'COALESCE(SUM(quantity), 0)'])
            ->where('sku = ?', $sku)
            ->where('stock_id = ?', $stockId)
            ->where($this->rowsOfOrder($orderId, $incrementId));
        return (float) $connection->fetchOne($select);
    }

    /**
     * Whether a reservation row for the sku names the order only by an increment id that orders of several stores carry.
     *
     * Such a row cannot be told apart between those orders (its metadata
     * names no store), so it is counted for none of them; registering or
     * compensating the sku as if it were not there could reserve the
     * quantity a second time. Its caller refuses the row instead.
     */
    public function hasAmbiguousRows(OrderInterface $order, string $sku): bool
    {
        $incrementId = (string) $order->getIncrementId();
        if ($incrementId === '' || $this->isUniqueIncrementId($incrementId)) {
            return false;
        }
        $stockIds = $this->stockIdsWithRows();
        if ($stockIds === []) {
            return false;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('inventory_reservation'), ['count' => 'COUNT(*)'])
            ->where('stock_id IN (?)', $stockIds)
            ->where('sku = ?', $sku)
            ->where($this->namesNoEntityId())
            ->where("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = ?", $incrementId);
        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * The stocks that hold any reservation row.
     *
     * The reservation table's only index leads with the stock id, so a
     * lookup by sku names the stocks: a filter on the sku alone reads the
     * whole table. They are read off that index (a loose scan, one entry
     * per stock), not off inventory_stock, so rows on a stock deleted since
     * still count.
     *
     * @return int[]
     */
    private function stockIdsWithRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_reservation');
        return array_map('intval', $connection->fetchCol($connection->select()->distinct()->from($table, ['stock_id'])));
    }

    /**
     * The condition on inventory_reservation.metadata for a row that names no order entity id.
     */
    private function namesNoEntityId(): string
    {
        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_id')), '') IN ('', '0', 'null')";
    }

    /**
     * The condition on inventory_reservation.metadata that picks the order's own rows.
     *
     * A row naming an order entity id (every writer of Magento's does, as a
     * string or a number) belongs to that order alone, so another store's
     * order with the same increment id never counts here: Magento keeps an
     * increment id unique per store only. A row naming no entity id is
     * matched by its increment id, and only while no other order carries
     * that increment id; an ambiguous one is counted for neither order, and
     * hasAmbiguousRows() tells its callers to refuse the sku.
     * Read as JSON, so an id that is a prefix of another, or holds a
     * character the JSON escapes, matches itself only.
     */
    private function rowsOfOrder(int $orderId, string $incrementId): string
    {
        $connection = $this->resourceConnection->getConnection();
        $objectId = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_id'))";
        $condition = $objectId . ' = ' . $connection->quote((string) $orderId);
        if ($incrementId !== '' && $this->isUniqueIncrementId($incrementId)) {
            $condition .= ' OR (' . $this->namesNoEntityId()
                . " AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.object_increment_id')) = " . $connection->quote($incrementId) . ')';
        }
        return $condition;
    }

    /**
     * Whether a single order carries the increment id, across every store.
     */
    private function isUniqueIncrementId(string $incrementId): bool
    {
        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('sales_order'), ['count' => 'COUNT(*)'])
            ->where('increment_id = ?', $incrementId);
        return (int) $connection->fetchOne($select) <= 1;
    }
}
