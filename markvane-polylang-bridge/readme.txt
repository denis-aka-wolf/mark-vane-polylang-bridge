=== Mark Vane Polylang Bridge ===
Contributors: markvane
Tags: polylang, multilingual, translations
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Preserves legacy Russian/English page-pair metadata and registers those pairs using Polylang's native translation API.

== Description ==

The plugin discovers existing page pairs from legacy metadata containing a JSON object with numeric "ru" and "en" page IDs. It does not require a hard-coded metadata key.

When Polylang is active and languages with slugs ru and en exist, the plugin:
* assigns a language only when a paired page currently has no language;
* links correctly assigned pairs with pll_save_post_translations();
* leaves conflicting language assignments untouched;
* keeps the legacy metadata intact.

It does not change page content, slugs, URLs, statuses, or menus.

== Administration ==

Open Tools > Polylang Bridge to inspect detected pairs and run a manual synchronization.

== REST API ==

Authenticated administrators can use GET /wp-json/mark-vane-polylang-bridge/v1/pairs and POST /wp-json/mark-vane-polylang-bridge/v1/sync.

== Changelog ==

= 1.2.0 =
* Register legacy RU/EN pairs with the native Polylang API.
* Add a diagnostic admin page and administrator-only REST endpoints.
* Preserve legacy metadata and avoid overwriting existing language assignments.

= 1.1.0 =
* Legacy metadata-based RU/EN page pairing.
