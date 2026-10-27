# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## Unreleased

### Added
* The package: the Magento Inventory half of `hyva-themes/magento2-inventory-order-reservation`, as its own module sequenced on the Inventory modules, so every constructor names the Inventory interface it needs and a store without Inventory never installs it. It holds the inventory handler (the `order_placed` reservation for what each sku still owes), the coordinated compensation and the three plugins on Magento's repair tools: the compensation writer, the inconsistency listing and the compensation command.
* The handler answers for each owed sku (placed, balanced, or refused with the reason and what remains per stock), so the core completes rows one by one; a surplus refuses its row while the order's other rows are placed in the same run. The coordinated compensation reads the named sku's row first: a refused or done row is judged without registering the order's other rows, an owed row has the registration run first and is refused as pending when it is still owed then. The inconsistency listing leaves out the skus the core still owes and keeps an order's other skus.
