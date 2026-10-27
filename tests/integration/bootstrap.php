<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Bootstrap for the integration tests: a live Magento with both packages,
 * found through MAGENTO_ROOT (default /var/www/html), booted through
 * app/bootstrap.php. The store fixture (StoreCase) is the core package's,
 * included from the installed core.
 */

declare(strict_types=1);

$root = getenv('MAGENTO_ROOT') ?: '/var/www/html';
if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "tests: set MAGENTO_ROOT to a Magento install\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}
require $root . '/app/bootstrap.php';
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Hyva\\InventoryOrderReservationMsi\\Test\\Integration\\', __DIR__ . '/');
$coreTests = null;
foreach ([
    $root . '/vendor/hyva-themes/magento2-inventory-order-reservation/tests/integration',
    __DIR__ . '/../../../inventory-order-reservation/tests/integration',
] as $candidate) {
    if (is_dir($candidate)) {
        $coreTests = realpath($candidate);
        break;
    }
}
if ($coreTests === null) {
    fwrite(STDERR, "tests: the core package's tests/integration directory was not found\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}
$loader->addPsr4('Hyva\\InventoryOrderReservation\\Test\\Integration\\', $coreTests . '/');
$objectManager = \Magento\Framework\App\Bootstrap::create($root, $_SERVER)->getObjectManager();
try {
    $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException) {
    // already set
}
\Hyva\InventoryOrderReservation\Test\Integration\StoreCase::boot($objectManager);
