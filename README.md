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

## Changelog

### 0.3.0

- Filter values come from a searchable picker that lists the values in use: authors, terms with their parent names and custom field values.
- Date filters cover on, before, after, between, today, this week, this month, and the last or next number of days, with a date picker.
- Filter chips sit on their own row. Clicking a chip opens its rule, Clear all removes every rule, and the row shows "Any of:" when any rule may match.
- WordPress's own filter row steps aside on screens with the Corinthian filter bar.
- A filter link with rules that no longer apply says which rules were left out, Apply points at incomplete rules, and a list with no matches offers Clear filters.
- The filter panel takes focus when it opens, Escape closes it, and screen readers hear the result count.
- Corinthian Admin Column Manager 0.2.0 or newer is required.
- Segments keep the sort, search and status along with the rules, and can be renamed or updated from the segments menu.
- A segment can be the default view: your own, or one an administrator sets for everyone.
- The filter bar names the active segment and marks it as edited when the list no longer matches it.
- Deleting a segment happens at once, with Undo.
- Importing or exporting column sets leaves out network screens unless you can manage the network.
- CSV export neutralises formula cells that start with any Unicode space.
- Uninstall cleans up every site on large networks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
