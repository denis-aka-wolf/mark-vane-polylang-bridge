# Mark Vane Polylang Bridge

A compatibility plugin for the `markvane.tech` WordPress site. It discovers existing Russian/English page pairs from legacy translation metadata and registers those pairs with Polylang's native translation API.

## Safety

- Keeps the existing plugin directory and main-file slug: `markvane-polylang-bridge/markvane-polylang-bridge.php`.
- Does not delete or rewrite legacy post metadata.
- Does not change page content, slugs, permalinks, publication status, or menus.
- Assigns `ru` or `en` only when a paired page has no language. Existing language assignments are not silently overwritten.
- Does nothing when the Polylang API is unavailable.

Open **Tools → Polylang Bridge** to inspect discovered pairs and manually synchronize them.

## REST API

- `GET /wp-json/mark-vane-polylang-bridge/v1/pairs` lists discovered pairs.
- `POST /wp-json/mark-vane-polylang-bridge/v1/sync` synchronizes pairs and requires an administrator.

## Requirements

- WordPress 6.0+
- Polylang 3.x
- PHP 7.4+
