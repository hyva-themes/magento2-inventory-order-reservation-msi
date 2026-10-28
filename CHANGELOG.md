# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## 0.1.0 (2026-10-28)

### Fixed
* A row whose product the catalog renamed after the order was recorded is refused with the reason, naming `inventory:order-reservation:settle`, instead of being read as owing nothing and marked balanced with no reservation written. The remaining inconsistency of a row is read on the lines of the product the row was recorded for, whatever the catalog calls it now.
* The lookup of the stocks that hold rows for an order and sku names the stocks that hold reservations (read off the same index), so it reads the reservation table through its index (`stock_id`, `sku`, `quantity`) instead of scanning it, inside the order's lock.
* What an order owes is read per product, as Magento's placement resolves a line (`GetSkuFromOrderItem`) and as the core recorded the row: the catalog's sku and type for the line's product id. A grouped product's child (line type `grouped`, which Inventory does not manage) and a product with custom options (a line sku such as `SHIRT-engr`) were read as owing nothing, so their registration ended balanced with no reservation written, and a compensation for them was refused as already balanced.

### Changed
* `composer.json` declares no `version`; the package's version is its git tag, as the core's is. `dev-main` is aliased as `1.0.x-dev`, as in the core.

### Added
* The package: the Magento Inventory half of `hyva-themes/magento2-inventory-order-reservation`, as its own module sequenced on the Inventory modules, so every constructor names the Inventory interface it needs and a store without Inventory never installs it. It holds the inventory handler (the `order_placed` reservation for what each sku still owes), the coordinated compensation and the three plugins on Magento's repair tools: the compensation writer, the inconsistency listing and the compensation command.
* The handler answers for each owed sku (placed, balanced, or refused with the reason and what remains per stock), so the core completes rows one by one; a surplus refuses its row while the order's other rows are placed in the same run. The coordinated compensation reads the named sku's row first: a refused or done row is judged without registering the order's other rows, an owed row has the registration run first and is refused as pending when it is still owed then. The inconsistency listing leaves out the skus the core still owes and keeps an order's other skus.
* The placement is written on the stock the order was recorded on: the handler builds the rows Magento's placement service would build (the reservation builder, the stock id the obligation carries, Magento's metadata and product-type filter) and appends them through Inventory's writer in one call, instead of asking the service, which resolves the stock from the website's mapping of the day.
* Completion is decided per row over every stock that matters to it (the recorded stock, the stock the website sells from now, every stock holding a row for the order and sku), with the order's expectation counted on the current stock only, as Magento's listing counts it: nothing remains, balanced; a lack on the recorded stock alone, placed there; anything elsewhere or a surplus, refused naming each stock and its remainder for a compensation there. The coordinated compensation is judged on the stock it names, a stock the order has nothing to do with being balanced there, and settles a refused row when nothing remains on any stock.
* The package conflicts with `yireo/magento2-replace-inventory`: a store that removed Inventory through it satisfies the package's Inventory requirements on paper and would install it, then fail to compile; Composer refuses the install there instead.
