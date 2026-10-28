<?php
/**
 * Hyvä Themes - https://hyva.io
 * Copyright © Hyvä Themes. All rights reserved.
 * See COPYING.txt for license details.
 *
 * Bootstrap for the package's standalone unit tests: a Magento vendor
 * with Inventory is borrowed through MAGENTO_VENDOR_PATH or a sibling
 * install; the core package comes from that vendor or from a sibling
 * checkout. Magento's factories and extension classes are generated on
 * demand, as Magento's own unit framework generates them.
 */

declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

$packageRoot = __DIR__ . '/..';
$candidates = [
    getenv('MAGENTO_VENDOR_PATH') ?: null,
    realpath($packageRoot . '/../../vendor/autoload.php'),
    realpath($packageRoot . '/vendor/autoload.php'),
];
$autoload = null;
foreach ($candidates as $candidate) {
    if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "tests: set MAGENTO_VENDOR_PATH to a Magento vendor/autoload.php\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('Hyva\\InventoryOrderReservationMsi\\', $packageRoot . '/src/', true);
$loader->addPsr4('Hyva\\InventoryOrderReservationMsi\\Test\\Unit\\', $packageRoot . '/tests/unit/', true);
// the core package from a sibling checkout, for a borrowed vendor that does not
// hold it. Appended, so a core in the vendor still wins. No class_exists() check
// first: Composer remembers a class it failed to find, and would not look again.
foreach (['magento2-inventory-order-reservation', 'inventory-order-reservation'] as $sibling) {
    $siblingSrc = realpath($packageRoot . '/../' . $sibling . '/src');
    if ($siblingSrc !== false) {
        $loader->addPsr4('Hyva\\InventoryOrderReservation\\', $siblingSrc . '/');
        break;
    }
}
$generated = sys_get_temp_dir() . '/hyva-inventory-order-reservation-msi-generated';
$autoloader = new GeneratedClassesAutoloader(
    [new FactoryGenerator(), new ExtensionAttributesGenerator(), new ExtensionAttributesInterfaceGenerator()],
    new Io(new File(), $generated)
);
spl_autoload_register([$autoloader, 'load']);
