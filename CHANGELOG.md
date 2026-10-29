# Changelog

All notable changes to this plugin are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the version numbers match the `Version` field in the plugin header.

## [Unreleased]

## [1.1.35] - 2026-10-29

First version published in this repository. Earlier versions were
developed privately and are not recorded here.

### Included

- Automatic stock sync with Morabaa POS every 20 minutes (WP-Cron),
  plus manual sync in batches.
- Product linking by Morabaa Item ID, SKU or barcode, and name-similarity
  suggestions; bulk linking of exact matches.
- Import of Morabaa items as new WooCommerce products.
- Configurable field mapping for the API response.
- Arabic admin dashboard with connection status, linked/unlinked products,
  per-product last sync and a barcode diagnostic tool.
- Encrypted storage of the App Secret (OpenSSL with the WordPress salts).

[Unreleased]: https://github.com/yahyakhalid/morabaa-woo-sync/compare/962b1fa...HEAD
[1.1.35]: https://github.com/yahyakhalid/morabaa-woo-sync/commit/962b1fa
