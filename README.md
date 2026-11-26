# Mark Vane Polylang Bridge

A WordPress compatibility and administration plugin for safely managing Russian/English page translations through Polylang's native API.

## Features

- Discovers existing RU/EN pairs stored in legacy JSON or serialized page metadata.
- Creates and manages translation pairs from the WordPress admin dashboard.
- Edits or unlinks pairs without deleting pages.
- Detects language conflicts and existing translation-group conflicts instead of silently overwriting them.
- Preserves legacy metadata. Manually unlinked legacy pairs are excluded from future automatic synchronization until explicitly restored.
- Provides manual and event-driven automatic synchronization.
- Shows per-pair diagnostics and a recent activity log.
- Exposes administrator-only REST endpoints for listing and synchronizing pairs.

## Safety guarantees

- Does not change page content, titles, slugs, permalinks, publication status, or menus.
- Does not delete WordPress pages.
- Assigns ru or en only when a page has no language assigned.
- Does not merge pages that already belong to another translation group.
- Refuses to unlink groups containing additional translations, preventing accidental damage to third-language associations.
- Keeps original legacy metadata intact.

## Performance

The plugin no longer scans page metadata on every front-end request. Pair discovery is cached for five minutes and the cache is invalidated when page metadata changes. Automatic synchronization is scheduled through WP-Cron only after relevant metadata changes or a one-time upgrade/activation event.

## Administration

Open **Polylang Bridge** from the top-level WordPress admin sidebar.

To create a pair, choose an existing Russian page and an existing English page. To change a pair, use **Изменить** in the pair table. To unlink it, use **Отвязать** and confirm the action. Unlinking removes only the Polylang association; pages and legacy metadata remain untouched.

If a pair is manually unlinked but still exists in legacy metadata, the plugin stores an ignore marker so automatic synchronization will not recreate the association. To restore it, create/save the same pair from the dashboard.

## REST API

- GET /wp-json/mark-vane-polylang-bridge/v1/pairs lists pairs and diagnostic status.
- POST /wp-json/mark-vane-polylang-bridge/v1/sync runs synchronization.

Both endpoints require an authenticated administrator with manage_options.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Polylang with language slugs ru and en

## License

GNU General Public License v2.0 or later. See LICENSE.
