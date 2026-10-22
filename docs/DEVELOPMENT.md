# Development

## Setup

```sh
composer install
npm ci
npm run env:start
```

`npm run env:start` starts WordPress and MariaDB in Docker on http://localhost:8895 (user `admin`, password `admin`). The plugin folder is mounted **read-only**, so nothing in WordPress can change or delete the source. `npm run env:stop` removes the site and its database.

There is no build step: the PHP runs as written, and the scripts in `assets/` use the `wp.*` globals WordPress already loads.

## Commands

| Command | What it runs |
| --- | --- |
| `composer lint` | PHPCS with the WordPress standard and PHPCompatibility from PHP 7.4 |
| `composer lint:fix` | PHPCBF, which fixes what PHPCS can fix on its own |
| `composer analyse` | PHPStan level 6 with WordPress and WP-CLI stubs |
| `composer test` | PHPUnit unit tests |
| `npm run test:e2e` | Playwright end-to-end tests against the Docker site, in headless Chrome |
| `npm run screenshots` | Regenerates the screenshots in `.wordpress-org/` |
| `npm run demo` | Records the README demo GIF in `.github/media/` (needs ffmpeg) |
| `bin/build-zip.sh` | Builds `dist/agent-governance.zip`, leaving out everything in `.distignore` |
| `bin/release.sh next` | Shows the version the next release would get |

## Tests

- **Unit tests** (`tests/Unit/`) mock WordPress functions with [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey/), so they need no database and run in about a second. CI runs them on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.
- **End-to-end tests** (`tests/e2e/`) drive the real wp-admin in Chrome. Each test checks the outcome in the database through WP-CLI as well as on screen. The run empties the site's content before it starts, so point it only at the throwaway Docker site.

## Screenshots and demo

`npm run screenshots` and `npm run demo` drive the same Docker site, where this is the only active plugin, so no other plugin's menus or notices end up in the pictures. Screenshots are saved at twice the viewport size for sharp text. The demo is recorded from Chrome's own frames with a drawn pointer and turned into a GIF by ffmpeg (`brew install ffmpeg` or `apt install ffmpeg`).

## Code layout

Classes live in `src/` under the `AryanX\AgentGovernance\` namespace and follow PSR-4: `AryanX\AgentGovernance\Admin\Foo` is `src/Admin/Foo.php`. The main plugin file registers a small PSR-4 autoloader, so no `vendor/` folder ships with the plugin. Every class that adds hooks implements `Service` and is wired up in `Plugin`.
