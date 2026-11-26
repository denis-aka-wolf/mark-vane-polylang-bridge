## 1.4.5
- Make REST synchronization report its actual result and counters.
- Return explicit HTTP errors for unavailable Polylang, skipped/failed pairs, and unexpected exceptions.
- Record REST synchronization outcomes in the activity log.

# Changelog

## 1.4.0
- Add admin workflows to create, edit, and unlink translation pairs.
- Add validation for page types, language assignments, and existing Polylang translation groups.
- Preserve legacy metadata and add ignore markers for manually unlinked legacy pairs.
- Replace front-end request synchronization with scheduled, metadata-triggered WP-Cron synchronization.
- Cache pair discovery and invalidate the cache when page metadata changes.
- Add per-pair diagnostics and a capped activity log.
- Update plugin documentation and package the GPL license inside the plugin directory.

## 1.3.0
- Add a dedicated WordPress admin menu and dashboard.
- Add an option to enable or disable automatic synchronization.
- Keep manual synchronization available from the dashboard.

## 1.2.0
- Discover legacy RU/EN page pairs and register them through Polylang's native API.
- Add diagnostics and manual synchronization.

## 1.1.0
- Add legacy metadata-based RU/EN page pairing.
