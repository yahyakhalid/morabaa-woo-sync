=== Morabaa WooCommerce Inventory Sync ===
Tags: woocommerce, inventory, stock, pos, sync
Stable tag: 1.1.35
Requires Plugins: woocommerce

Keeps WooCommerce stock in sync with Morabaa POS (مربّع) through the Morabaa API, with an Arabic admin dashboard.

== Description ==

Shops that sell from a Morabaa point of sale and online at the same time often show products online that are already out of stock in the shop, or the other way round. This plugin reads quantities from Morabaa and updates WooCommerce automatically, and links every WooCommerce product to its matching Morabaa item.

مزامنة مخزون WooCommerce مع نظام مربّع POS عبر الـ API، مع لوحة تحكم عربية.

= Features =

* Automatic stock sync every 20 minutes through WP-Cron, plus a manual sync button that runs in batches.
* Product linking by Morabaa Item ID (a manual field on the product edit screen), by SKU or barcode (including items that return several barcodes), or by name-similarity suggestions for products without a SKU.
* Bulk linking of all exact matches in one click.
* Import Morabaa items into WooCommerce as new products, with search and selection.
* Sale price read from the item's default unit.
* Field mapping: choose which field in the API response holds the ID, name, price, quantity and SKU.
* Arabic dashboard: connection status, linked and unlinked products, last sync per product, and a barcode diagnostic tool.
* The App Secret is stored encrypted in the database using OpenSSL and the WordPress salts.

= Requirements =

* WordPress with WooCommerce active.
* A Morabaa account with an App ID and App Secret.
* PHP with the OpenSSL extension.

== Installation ==

1. Download the repository as a ZIP file (Code, then Download ZIP).
2. In WordPress go to Plugins, Add New, Upload Plugin, and choose the file.
3. Activate the plugin. A Morabaa menu appears in the admin dashboard.
4. Open the plugin page and enter the App ID and App Secret from your Morabaa account.
5. Keep the default API address `https://api.mymorabaa.com/v1/items` unless you have a different one.
6. Save, then pull the status once to confirm the connection.
7. Link products: start with SKU linking, then review the suggestions for the rest.
8. Turn on automatic sync.

== Frequently Asked Questions ==

= Does the plugin change anything in Morabaa? =

No. It only reads from Morabaa (GET requests). All updates happen on the WooCommerce side.

= The sync does not run on time. Why? =

WP-Cron runs on site visits. On a low-traffic site, call `wp-cron.php` from a real server cron job so the 20-minute sync stays regular.

== Changelog ==

= 1.1.35 =
* First version published in this repository.

Morabaa (مربّع) is a trademark of its owners: https://mymorabaa.com
