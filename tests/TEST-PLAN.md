# Manual test plan

Run these checks on a staging copy before deploying to a production site. The current markvane.tech site has no staging environment available; do not run create, edit, unlink, or other destructive pair-management tests against production pages.

## Compatibility and baseline
1. Confirm WordPress, PHP, and Polylang are active.
2. Confirm Polylang has language slugs ru and en.
3. Open Polylang Bridge and compare discovered pairs with the site's known RU/EN page pairs.
4. Confirm page IDs, titles, slugs, URLs, statuses, content, and menus are unchanged.

## Pair management
1. Create a pair from two existing pages with no language assigned. Confirm Polylang assigns RU/EN and links them.
2. Try selecting a page already assigned to another language. Confirm the operation is rejected without changing the assignment.
3. Try selecting a page linked to a different translation group. Confirm the operation is rejected.
4. Edit a pair to use a different English page. Confirm the old pair is detached and the new pair is linked.
5. Force a failed replacement by selecting a page with a language conflict. Confirm the old pair is restored.
6. Unlink a legacy pair. Confirm the pages and original metadata remain, and automatic synchronization does not relink it.
7. Recreate/save that same pair in the dashboard. Confirm the ignore marker is cleared and the pair is linked again.
8. Try to unlink a group with a third language. Confirm the plugin refuses and does not alter the group.

## Synchronization and performance
1. Run manual synchronization and review the activity log.
2. Change legacy pair metadata. Confirm a WP-Cron event is scheduled and processed.
3. Disable automatic synchronization, change metadata, and confirm no automatic task is scheduled.
4. Re-enable it and confirm a task is scheduled.
5. Load several front-end pages. Confirm no pair scan or Polylang linking is triggered by ordinary page requests.
6. Confirm the pair list is cached and refreshes after page metadata changes.

## REST and permissions
1. As an administrator, request the pairs endpoint and run the sync endpoint.
2. As a user without manage_options, confirm both endpoints are denied.
3. Confirm all admin POST actions reject missing or invalid nonces.

## Release checks
1. Run `php -l markvane-polylang-bridge/markvane-polylang-bridge.php` from the repository root.
2. Confirm `dist/markvane-polylang-bridge-1.4.1.zip` contains one top-level `markvane-polylang-bridge/` directory.
3. Confirm that directory contains the PHP file, readme.txt, CHANGELOG.md, and LICENSE.
4. Install the ZIP on staging and verify the active plugin version is 1.4.1. If staging is unavailable, verify only the installed version and read-only diagnostics on production; defer destructive tests.
