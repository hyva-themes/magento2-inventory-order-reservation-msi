<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Plugin\Inventory;

use Hyva\InventoryOrderReservation\Model\ResourceModel\ObligationResource;
use Magento\InventoryReservationCli\Model\GetSalableQuantityInconsistencies;
use Magento\InventoryReservationCli\Model\SalableQuantityInconsistency;

/**
 * The inconsistency listing is diagnostic. A sku whose registration the
 * core still owes is not an inconsistency for an administrator to
 * compensate; it is the core's to register (the cron, the command, the
 * admin message). Those skus are left out of the listing, so that the
 * operator workflow (list, then compensate) never compensates them, and
 * the order's other skus (refused for a compensation, or done) stay
 * listed; an order with nothing else left is left out.
 */
class LeaveOwedOrdersOutOfInconsistenciesPlugin
{
    public function __construct(
        private readonly ObligationResource $obligations
    ) {
    }

    public function afterExecute(GetSalableQuantityInconsistencies $subject, \Generator $result): \Generator
    {
        foreach ($result as $bunch) {
            $kept = [];
            foreach ((array) $bunch as $key => $inconsistency) {
                $items = $this->withoutOwedSkus($inconsistency);
                if ($items === []) {
                    continue;
                }
                $inconsistency->setItems($items);
                $kept[$key] = $inconsistency;
            }
            yield $kept;
        }
    }

    /**
     * The inconsistency's items without the skus the core still owes.
     *
     * @return array<string, float> sku => quantity
     */
    private function withoutOwedSkus(SalableQuantityInconsistency $inconsistency): array
    {
        $owed = [];
        foreach ($this->obligations->owedForOrder((int) $inconsistency->getObjectId()) as $row) {
            $owed[$row->sku] = true;
        }
        return $owed === [] ? $inconsistency->getItems() : array_diff_key($inconsistency->getItems(), $owed);
    }
}
