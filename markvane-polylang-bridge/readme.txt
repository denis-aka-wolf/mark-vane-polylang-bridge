=== Mark Vane Polylang Bridge ===
Contributors: markvane
Tags: polylang, multilingual, translations
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.4.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Safely manages legacy Russian/English page pairs through Polylang's native translation API.

== Description ==

The plugin discovers existing RU/EN page pairs from legacy page metadata containing JSON or serialized arrays with numeric "ru" and "en" page IDs. It also supports pairs created in the plugin dashboard.

Safety rules:
* Existing language assignments are never silently overwritten.
* Pages already belonging to another translation group are not merged automatically.
* Original legacy metadata is preserved.
* Unlinking removes the Polylang association, not the pages or their content.
* Unlinked legacy pairs are recorded as ignored so automatic synchronization does not immediately recreate the association.
* Page content, titles, slugs, URLs, publication status, and menus are not changed.

== Administration ==

Open the top-level **Polylang Bridge** menu in the WordPress admin sidebar.

The dashboard provides:
* RU/EN pair inventory and status diagnostics.
* Create, edit, and unlink actions for existing WordPress pages.
* Language and translation-group conflict protection.
* Automatic synchronization setting and manual synchronization.
* A recent activity log.

Automatic synchronization is event-driven. The plugin does not scan pages on ordinary front-end requests. When legacy pair metadata changes, a single WP-Cron task is scheduled. As a fallback for hosting environments where WP-Cron is delayed or disabled, the admin area checks for pending synchronization no more than once per minute. The pair inventory is cached for five minutes and invalidated when page metadata changes. Every newly saved translation pair is verified from both the Russian and English page records and the result is written to the activity log.

Unlinking a pair preserves its original metadata and records an ignore entry. To restore the pair, create/save that pair again from the dashboard.

== REST API ==

Authenticated administrators can use:
* GET /wp-json/mark-vane-polylang-bridge/v1/pairs
* POST /wp-json/mark-vane-polylang-bridge/v1/sync

Both endpoints require the manage_options capability.

== Requirements ==

* WordPress 6.0+
* Polylang with languages whose slugs are ru and en
* PHP 7.4+

== Changelog ==

= 1.4.3 =
* Run the throttled synchronization fallback during authenticated and public REST API initialization as well as admin requests, so API-driven site operations can also process pending pairs.

= 1.4.2 =
* Add a throttled admin-side fallback when WP-Cron is delayed or unavailable.
* Verify saved Polylang translation relationships from both language sides.
* Record successful and failed synchronization results in the activity log.

= 1.4.1 =
* Validate legacy pair candidates to reduce false positives from unrelated metadata.
* Detect both JSON and PHP-serialized pair metadata when scheduling automatic synchronization.
* Clear scheduled synchronization and cached pair data on plugin deactivation.


= 1.4.0 =
* Add dashboard management for creating, editing, and unlinking RU/EN page pairs.
* Add conflict checks for language assignments and existing translation groups.
* Preserve legacy metadata and prevent manually unlinked pairs from being auto-linked again.
* Move automatic synchronization to a scheduled event triggered by metadata changes.
* Cache the discovered pair inventory and invalidate it when page metadata changes.
* Add an activity log and per-pair diagnostic statuses.
* Update documentation and include the GPL license in the plugin package.

= 1.3.0 =
* Add a dedicated WordPress admin menu and dashboard.
* Add an option to enable or disable automatic synchronization.
* Keep manual synchronization available from the dashboard.

= 1.2.0 =
* Register legacy RU/EN pairs with the native Polylang API.
* Add a diagnostic admin page and administrator-only REST endpoints.
* Preserve legacy metadata and avoid overwriting existing language assignments.

= 1.1.0 =
* Add legacy metadata-based RU/EN page pairing.
