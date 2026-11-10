<h1 align="center">Corinthian Admin Column Manager PRO</h1>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/LICENSE-GPL--2.0--or--later-5C9E31?style=for-the-badge" alt="License"></a>
  <a href="https://github.com/jgalea"><img src="https://img.shields.io/badge/BUILT%20BY-JEAN%20GALEA-8A2BE2?style=for-the-badge" alt="Built by"></a>
</p>

<p align="center"><strong>Smart filters, inline and bulk editing, CSV export, conditional formatting and integrations for the Corinthian admin column manager.</strong></p>

## What it adds

Corinthian Admin Column Manager PRO extends the free Corinthian Admin Column Manager plugin for WordPress:

- Smart filters on any column, with saved segments
- Inline editing and bulk editing, checked against each user's permissions
- CSV export of the filtered, sorted list
- Conditional formatting for rows and cells
- Quick add from the list table
- Column set transfer as JSON, and export of a set as PHP
- Integrations for WooCommerce, Advanced Custom Fields, Meta Box, Pods, Yoast SEO, Rank Math, SEOPress, The Events Calendar, BuddyPress and WP RSS Aggregator
- Plain-English filters and suggested values through the WordPress 7.0 AI client, when an AI provider is connected
- Column sets, filters, segments and bulk edits through the WordPress Abilities API

## Requirements

- WordPress 6.9 or later
- PHP 8.1 or later
- The free Corinthian Admin Column Manager plugin, active

## Install

Download `corinthian-pro.zip` from the [latest release](https://github.com/jgalea/corinthian-pro/releases/latest) and upload it under Plugins → Add New → Upload Plugin. Updates arrive through the normal WordPress updates screen.

## Development

```sh
composer install
npm install
npm run build
bin/build.sh /path/to/empty/folder   # release zip from the committed HEAD
```

Integration tests run inside a WordPress test environment that also loads the free plugin from `../corinthian-admin-column-manager`. End-to-end scripts in `tests/e2e/` read `WP_CLI_CONTAINER` and `SHOTS_DIR` from the environment.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
