# Manual validation

This project does not use GitHub Actions or any other repository-hosted CI service.

Before publishing a plugin ZIP, perform these checks locally:

1. Run PHP syntax validation:
   `php -l markvane-polylang-bridge/markvane-polylang-bridge.php`
2. Confirm the plugin header version and `VERSION` constant match.
3. Confirm `readme.txt` Stable tag and `CHANGELOG.md` describe the same release.
4. Confirm the `LICENSE` file is included and the plugin header declares GPL-2.0-or-later.
5. Build a ZIP with exactly one top-level `markvane-polylang-bridge/` directory.
6. Inspect the ZIP contents and run PHP syntax validation against the extracted PHP file.
7. Install the ZIP on a staging site, verify activation, dashboard access, pair diagnostics, manual sync, and settings.
8. Test create, edit, and unlink operations only with disposable test pages. Never use production translation pairs for destructive tests.
9. After deployment, confirm the installed plugin version and check the site's RU and EN pages.

Example package command from the repository root:

```sh
zip -r markvane-polylang-bridge-1.4.1.zip markvane-polylang-bridge
```

Update the version in the command when preparing a later release.

## Version 1.4.1 additional checks

1. Add a JSON pair value to a test page's metadata and confirm a single sync event is scheduled.
2. Add a PHP-serialized array containing `ru` and `en` IDs to a test page's metadata and confirm a single sync event is scheduled.
3. Add unrelated metadata containing `ru` and `en` numeric IDs to a different page and confirm it is not discovered as a pair when that page is not one of the referenced pages.
4. Confirm malformed IDs, equal IDs, missing pages, and non-page post IDs are rejected.
5. Deactivate the plugin and confirm its `mvpb_run_scheduled_sync` event is cleared. Reactivate and confirm normal scheduling resumes.
6. Confirm the existing production RU/EN pairs, page content, slugs, URLs, publication statuses, and menus remain unchanged.
