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
