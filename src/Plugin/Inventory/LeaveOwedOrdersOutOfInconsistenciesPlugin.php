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
 * The inconsistency listing is diagnostic. An order whose registration
 * this module still owes is not an inconsistency for an administrator to
 * compensate; it is this module's to register (the cron, the command, the
 * admin message). It is left out of the listing so that the operator
 * workflow (list, then compensate) never compensates it.
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
                if ($this->isOwed($inconsistency)) {
                    continue;
                }
                $kept[$key] = $inconsistency;
            }
            yield $kept;
        }
    }

    private function isOwed(SalableQuantityInconsistency $inconsistency): bool
    {
        return $this->obligations->owedForOrder((int) $inconsistency->getObjectId()) !== [];
    }
}
