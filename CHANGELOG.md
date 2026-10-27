# Changelog

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

---

## Unreleased

### Added
* The package: the Magento Inventory half of `hyva-themes/magento2-inventory-order-reservation`, as its own module sequenced on the Inventory modules, so every constructor names the Inventory interface it needs and a store without Inventory never installs it. It holds the inventory handler (the `order_placed` reservation for what each sku still owes), the coordinated compensation and the three plugins on Magento's repair tools: the compensation writer, the inconsistency listing and the compensation command.
