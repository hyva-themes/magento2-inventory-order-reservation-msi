<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Test\Unit;

use Hyva\InventoryOrderReservation\Model\ProductIdentities;
use Hyva\InventoryOrderReservation\Model\ResourceModel\WebsiteStock;
use Hyva\InventoryOrderReservationMsi\Model\Compensation\RemainingInconsistency;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;

/**
 * A real RemainingInconsistency over an in-memory reservation table, so a
 * test runs its decisions (the rename, the ambiguous order number, the
 * remainders) as written. The table answers the service's queries by
 * their parts, as the SQL means them; the SQL itself is the integration
 * tests'. Every query is counted by kind in $queries.
 */
trait ReservationStore
{
    /** @var array<int, array{stock: int, sku: string, qty: float, object_id: ?string, increment: ?string}> */
    private array $reservations = [];

    /** @var array<string, int> query kind => times run */
    private array $queries = [];

    /**
     * @param array<int, array{sku: string, type: string}> $catalog what the catalog holds by product id
     * @param array<string, int> $ordersByIncrementId how many orders carry an increment id; one when not named
     */
    private function remainingOver(
        array $catalog = [],
        bool $synchronized = true,
        array $ordersByIncrementId = [],
        int $currentStock = 2
    ): RemainingInconsistency {
        $this->queries = [];
        $parts = new \WeakMap();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . $value . "'");
        $connection->method('select')->willReturnCallback(function () use ($parts): Select {
            $select = $this->createStub(Select::class);
            $parts[$select] = (object) ['table' => '', 'cols' => [], 'where' => []];
            $select->method('distinct')->willReturn($select);
            $select->method('from')->willReturnCallback(static function ($table, $cols = '*') use ($parts, $select): Select {
                $parts[$select]->table = $table;
                $parts[$select]->cols = (array) $cols;
                return $select;
            });
            $select->method('where')->willReturnCallback(static function ($condition, $value = null) use ($parts, $select): Select {
                $parts[$select]->where[] = [$condition, $value];
                return $select;
            });
            return $select;
        });
        $answer = fn (Select $select) => $this->answerQuery($parts[$select], $ordersByIncrementId);
        $connection->method('fetchCol')->willReturnCallback($answer);
        $connection->method('fetchOne')->willReturnCallback($answer);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name): string => $name);

        $allowed = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $allowed->method('execute')->willReturn(true);
        $managed = $this->createStub(StockItemConfigurationInterface::class);
        $managed->method('isManageStock')->willReturn(true);
        $configuration = $this->createStub(GetStockItemConfigurationInterface::class);
        $configuration->method('execute')->willReturn($managed);
        $websiteStock = $this->createStub(WebsiteStock::class);
        $websiteStock->method('idForWebsiteCode')->willReturn($currentStock);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $websites = $this->createStub(WebsiteRepositoryInterface::class);
        $websites->method('getById')->willReturn($website);
        $products = $this->createStub(ProductIdentities::class);
        $products->method('byProductIds')->willReturnCallback(function (array $ids) use ($catalog): array {
            $this->queries['products'] = ($this->queries['products'] ?? 0) + 1;
            return array_intersect_key($catalog, array_flip($ids));
        });
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'cataloginventory/options/synchronize_with_catalog' && $synchronized
        );
        return new RemainingInconsistency($resource, $allowed, $configuration, $websiteStock, $websites, $products, $config);
    }

    /**
     * A reservation row in the table; an object id of null names the order by its increment id only.
     */
    private function reserve(int $stockId, string $sku, float $qty, ?int $orderId, ?string $incrementId = null): void
    {
        $this->reservations[] = [
            'stock' => $stockId,
            'sku' => $sku,
            'qty' => $qty,
            'object_id' => $orderId === null ? null : (string) $orderId,
            'increment' => $incrementId,
        ];
    }

    /**
     * An open order of website 1 with one simple line per entry.
     *
     * @param array<int, array{0: int, 1: string, 2: float}> $lines product id, the line's sku, quantity ordered
     */
    private function openOrder(int $orderId, string $incrementId, array $lines): Order
    {
        $items = [];
        foreach ($lines as [$productId, $sku, $qty]) {
            $item = $this->createStub(Item::class);
            $item->method('getParentItem')->willReturn(null);
            $item->method('getProductOptions')->willReturn([]);
            $item->method('getProductId')->willReturn($productId);
            $item->method('getSku')->willReturn($sku);
            $item->method('getProductType')->willReturn('simple');
            $item->method('getQtyOrdered')->willReturn($qty);
            $item->method('getIsVirtual')->willReturn(false);
            $items[] = $item;
        }
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->createStub(Order::class);
        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $order->method('getItems')->willReturn($items);
        $order->method('getStore')->willReturn($store);
        return $order;
    }

    /**
     * What a query of the service returns on the table, read off its parts.
     *
     * @param object{table: string, cols: array, where: array<int, array{0: string, 1: mixed}>} $select
     * @param array<string, int> $ordersByIncrementId
     * @return int|float|int[]
     */
    private function answerQuery(object $select, array $ordersByIncrementId): int|float|array
    {
        if ($select->table === 'sales_order') {
            $this->countQuery('orders by increment id');
            return $ordersByIncrementId[(string) $select->where[0][1]] ?? 1;
        }
        if ($select->where === []) {
            $this->countQuery('stocks with rows');
            $stocks = array_values(array_unique(array_column($this->reservations, 'stock')));
            sort($stocks);
            return $stocks;
        }
        $rows = array_filter($this->reservations, fn (array $row): bool => $this->rowMatches($row, $select->where));
        if (isset($select->cols['total'])) {
            $this->countQuery('sum');
            return round(array_sum(array_column($rows, 'qty')), 4);
        }
        if (isset($select->cols['count'])) {
            $this->countQuery('ambiguous rows');
            return count($rows);
        }
        $this->countQuery('stocks of the order');
        return array_values(array_unique(array_column($rows, 'stock')));
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $where
     */
    private function rowMatches(array $row, array $where): bool
    {
        foreach ($where as [$condition, $value]) {
            $matches = match (true) {
                $condition === 'stock_id IN (?)' => in_array($row['stock'], array_map('intval', (array) $value), true),
                $condition === 'stock_id = ?' => $row['stock'] === (int) $value,
                $condition === 'sku = ?' => $row['sku'] === $value,
                str_contains($condition, "object_increment_id')) = ?") => $row['increment'] === $value,
                str_starts_with($condition, 'COALESCE(') => $row['object_id'] === null,
                default => $this->isRowOfOrder($row, $condition),
            };
            if (!$matches) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the row is one of the order's by the service's order condition: its entity id, or its increment id when the condition names one.
     */
    private function isRowOfOrder(array $row, string $condition): bool
    {
        preg_match("/object_id'\\)\\) = '([^']*)'/", $condition, $entity);
        preg_match("/object_increment_id'\\)\\) = '([^']*)'/", $condition, $increment);
        return $row['object_id'] === ($entity[1] ?? false)
            || (isset($increment[1]) && $row['object_id'] === null && $row['increment'] === $increment[1]);
    }

    private function countQuery(string $kind): void
    {
        $this->queries[$kind] = ($this->queries[$kind] ?? 0) + 1;
    }
}
