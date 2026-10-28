<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Hyva\InventoryOrderReservationMsi\Model\Compensation;

/**
 * A row whose product the catalog renamed after the order was recorded,
 * and where the order's reservations are now. With Synchronize with
 * Catalog on, Magento Inventory moves every reservation of the old sku to
 * the new one through its inventory.reservations.update queue consumer;
 * once no row of the order is left under the recorded sku, the row is
 * judged and placed under the catalog's sku (followed). Before the
 * consumer has run (pending), or with the setting off, when Magento
 * moves nothing (not synchronized), the order's reservations are split
 * or stay under the recorded sku, and the row is refused.
 */
class Rename
{
    public const FOLLOWED = 'followed';
    public const PENDING = 'pending';
    public const NOT_SYNCHRONIZED = 'not_synchronized';

    public function __construct(
        public readonly string $recordedSku,
        public readonly string $currentSku,
        public readonly string $state
    ) {
    }

    public function isFollowed(): bool
    {
        return $this->state === self::FOLLOWED;
    }
}
