# Magento Inventory support for the order reservation module

This package is the Magento Inventory (MSI) half of [`hyva-themes/magento2-inventory-order-reservation`](https://gitlab.hyva.io/hyva-pos/magento2-inventory-order-reservation). Install it on a store that runs MSI. Without it, the core module only knows how to deduct the legacy stock item.

The core module keeps a small table of what each API-created order still owes in stock, and makes sure that stock is written exactly once. This package does the actual writing on an MSI store: it places the `order_placed` reservation, and it teaches Magento's own repair commands about orders the core is still working on.

## Installing

```sh
composer require hyva-themes/magento2-inventory-order-reservation hyva-themes/magento2-inventory-order-reservation-msi
bin/magento setup:upgrade
```

The package requires the MSI modules it uses (`magento/module-inventory-sales`, `-reservations`, `-catalog`, `-configuration` and `-reservation-cli`) and the core module at `^0.1`.

If your store removed MSI with `yireo/magento2-replace-inventory`, Composer will refuse to install this package. That is on purpose. A replace package tells Composer the MSI modules are present, so without the conflict the install would go through and `setup:di:compile` would break on the missing interfaces. Stores without MSI install the core module alone.

## What it does

### Places the reservation

When the core registers an order, this package writes the `order_placed` reservation for it. It writes what Magento's own checkout would write, one row per sku with Magento's metadata, with one difference: the rows go to the stock the order was recorded on, not to whatever stock the website is mapped to today. Magento's placement service always resolves the stock from the current website mapping, so we build the rows ourselves and hand them to Inventory's writer, `AppendReservationsInterface`. Inventory's own plugin on that writer still applies, so products with "manage stock" off are skipped as usual.

The quantity is what the order still owes by Magento's own rule, not the quantity ordered. If the order was shipped, canceled or partly compensated by hand before the registration ran, those rows are already there and we do not place them again.

### Decides per sku what still needs doing

Before writing, we look at every stock that matters for a sku: the stock the order was recorded on, the stock the website sells from now, and any stock that already holds a row for this order and sku. Then:

- Nothing is missing anywhere: the sku is done, nothing is written.
- The only thing missing is on the recorded stock: we place it there.
- Something is missing on another stock, or the recorded stock holds more than the order owes: we refuse that sku and say which stock is off by how much. `inventory:order-reservation:list` shows the reason, and a manual compensation on the stock named fixes it.

The refusal is deliberate. We only write to the recorded stock, and only downward. If the website was moved to another stock between the order and its registration, or a shipment was split over two stocks, completing the order on today's mapping would hide a discrepancy. Refusing keeps it visible until someone compensates it. The other skus of the order are placed in the same run.

### Works with Magento's repair commands

Magento ships `inventory:reservation:list-inconsistencies` and `inventory:reservation:create-compensations`. This package hooks into both.

The listing leaves out skus the core still has to register. They are not inconsistencies for a merchant to fix, they are work the module has not done yet. Other skus of the same order stay listed as normal.

A manual compensation for an order the core knows about goes through a coordinated path, whether it comes from the command or from your own code calling `AppendReservationsInterface`. Under the order's lock:

1. We look at the sku's row first. A refused or done row is judged right away. An owed row gets the core's registration run first; if it is still owed after that, for example because this package was not installed when the order came in and still is not, the compensation is refused as pending.
2. The request is checked against what the order still owes on the stock it names. Already balanced: nothing is written and the command gets an error. The right amount, or part of it: written. Wrong direction, too much, or a sku the order does not have: refused with the current numbers.
3. A refused row becomes done once nothing is missing on any stock that matters for it.

Refusals and failures reach the caller as `CouldNotSaveException`, so the command prints them and exits non-zero. If anything fails inside the lock, the transaction rolls back and nothing is written. For rows that were written, the package prints a few notes under Magento's output: how much is still owed after a partial compensation, whether a registration ran first, and a warning when "Decrease Stock When Order is Placed" is off, because then Magento drops every row after printing it as compensated.

One known gap: a compensation for a product with "manage stock" off is dropped by Magento's own plugin before we see it, and Magento says nothing about it. We cannot improve on that from the outside.

Magento's reservation cleanup deletes balanced rows. We never read those rows to decide whether an order was registered; the core's own table decides that.

## Working on the module

Every class in this package names the MSI interfaces it needs in its constructor. The module is sequenced on the MSI modules, so it only loads where they exist.

Unit tests run against a Magento vendor directory that has MSI and the core module installed, or the core checked out next to this package:

```sh
MAGENTO_VENDOR_PATH=/path/to/magento/vendor/autoload.php php phpunit-10.phar
```

Magento's factories and extension classes are generated on the fly, the same way Magento's own unit test framework does it.

Integration tests run inside a development store that has both packages installed. They reuse the core's store fixture, `StoreCase`, place real orders for one sku and put its stock back afterwards. Run them from the package directory under the store:

```sh
MAGENTO_ROOT=/var/www/html php phpunit-10.phar -c phpunit.integration.xml.dist
```

## License

This package is dual-licensed under the Open Software License (OSL 3.0) and the Academic Free License (AFL 3.0). You may choose either license.

- Copyright © 2026-present Hyvä Themes. All rights reserved.
- OSL 3.0 text: `LICENSE.txt` or http://opensource.org/licenses/osl-3.0.php.
- AFL 3.0 text: `LICENSE_AFL.txt` or http://opensource.org/licenses/afl-3.0.php.
