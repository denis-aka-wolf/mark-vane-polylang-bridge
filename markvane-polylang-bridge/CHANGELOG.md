## 1.4.4
- Return synchronization counters for checked, already linked, repaired, skipped, and failed pairs.
- Verify translation relationships in both directions and repair incomplete one-sided associations.
- Add REST diagnostics for both translation maps and `pll_get_post()` lookups.
- Log already-correct relationships as verified.

## 1.4.3
- Run the throttled synchronization fallback during REST API initialization as well as admin requests, allowing REST-driven site operations to process pending pairs.

## 1.4.2
- Add an admin-side synchronization fallback, throttled to one run per minute, for hosts where WP-Cron is delayed or disabled.
- Verify every saved RU/EN relationship from both page records.
- Log successful and failed synchronization outcomes for easier diagnosis.

## 1.4.1
- Validate legacy pair metadata before accepting it, including strict numeric page IDs and ownership by one of the referenced pages.
- Schedule automatic synchronization for both JSON and PHP-serialized legacy pair metadata.
- Clear the plugin's scheduled synchronization event and pair cache on deactivation.

# Changelog

## 1.4.0
- Add create, edit, and unlink workflows for RU/EN page pairs in the WordPress admin.
- Add validation for page types, language assignments, and existing translation groups.
- Preserve legacy metadata and remember manually unlinked pairs to prevent automatic relinking.
- Move automatic synchronization from every front-end request to metadata-triggered WP-Cron tasks.
- Cache pair discovery and invalidate the cache when page metadata changes.
- Add a capped activity log.
- Include GPL-2.0-or-later license in the installable plugin folder.

## 1.3.0
- Add a dedicated WordPress admin menu and dashboard.
- Add automatic synchronization settings and manual synchronization.

## 1.2.0
- Register legacy RU/EN pairs through the native Polylang API.
- Add diagnostics and administrator-only REST endpoints.

## 1.1.0
- Add legacy metadata-based RU/EN page pairing.
