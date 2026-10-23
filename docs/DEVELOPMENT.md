# Development

## Setup

```sh
composer install
npm ci
npm run env:start
```

`npm run env:start` starts WordPress and MariaDB in Docker on http://localhost:8906 (user `admin`, password `admin`). The plugin folder is mounted **read-only**, so nothing in WordPress can change or delete the source. `npm run env:stop` removes the site and its database.

Port 8906 belongs to this plugin, so its site can run next to the sites of the other plugins. To use another port, set `WP_PORT` for both: `WP_PORT=9000 npm run env:start`, then `WP_PORT=9000 npm run test:e2e`.

There is no build step: the PHP runs as written, and the scripts in `assets/` use the `wp.*` globals WordPress already loads.

## Commands

| Command | What it runs |
| --- | --- |
| `composer lint` | PHPCS with the WordPress standard and PHPCompatibility from PHP 7.4 |
| `composer lint:fix` | PHPCBF, which fixes what PHPCS can fix on its own |
| `composer analyse` | PHPStan level 6 with WordPress and WP-CLI stubs |
| `composer test` | PHPUnit unit tests |
| `npm run test:e2e` | Playwright end-to-end tests against the Docker site, in headless Chrome, including the accessibility checks |
| `npm run test:e2e:legs` | The end-to-end tests on every WordPress and PHP version CI runs them on, one after the other (`bin/e2e-legs.sh`) |
| `npm run plugin-check` | Builds the zip and runs the WordPress.org Plugin Check on it in the Docker site, like CI does (`bin/plugin-check.sh`) |
| `npm run screenshots` | Regenerates the screenshots in `.wordpress-org/` |
| `npm run demo` | Records the README demo GIF in `.github/media/` (needs ffmpeg) |
| `bin/build-zip.sh` | Builds `dist/agent-governance.zip`, leaving out everything in `.distignore` |
| `bin/release.sh next` | Shows the version the next release would get |

## Tests

- **Unit tests** (`tests/Unit/`) mock WordPress functions with [Brain Monkey](https://giuseppe-mazzapica.gitbook.io/brain-monkey/), so they need no database and run in about a second. CI runs them on PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.
- **End-to-end tests** (`tests/e2e/`) drive the real wp-admin in Chrome. Each test checks the outcome in the database through WP-CLI as well as on screen. The run empties the site's content before it starts, so point it only at the throwaway Docker site.
- **Accessibility checks** (`tests/e2e/a11y.spec.js`) run with the end-to-end tests. They open every screen in `tests/e2e/a11y-screens.json` and run [axe-core](https://github.com/dequelabs/axe-core) on the plugin's own markup. Serious and critical findings fail the test; moderate and minor ones are only printed.

### Every WordPress version CI tests

CI runs the end-to-end tests on the latest WordPress and on the oldest versions `readme.txt` promises. `npm run test:e2e:legs` runs the same legs locally. For each leg it stops the site, starts it again with that leg's PHP image, downloads that WordPress version, installs it, sets the theme and runs the tests. A summary at the end lists each leg with its WordPress and PHP version and the result.

```sh
npm run test:e2e:legs                      # every leg
npm run test:e2e:legs -- --leg latest      # one leg
npm run test:e2e:legs -- -- -g "abilities" # Playwright arguments go after a second --
```

Each leg starts from an empty site, and the site is stopped at the end (`--keep` leaves the last leg running).

### Accessibility screens

`tests/e2e/a11y-screens.json` belongs to this plugin. List every admin screen it adds, and the modals and states that only show after a click or with data:

```json
{
  "include": ["#wpbody-content"],
  "exclude": [],
  "screens": [
    {
      "name": "Tools page with a running job",
      "setup": [{ "wp": ["post", "create", "--post_title=Test", "--porcelain"], "save": "post" }],
      "url": "/wp-admin/tools.php?page=example&post={post}",
      "steps": [{ "click": ".example-open" }, { "waitFor": ".example-modal" }],
      "include": [".example-modal"],
      "cleanup": [["post", "delete", "{post}", "--force"]]
    }
  ],
  "skip": {
    "Tools page with a running job": "Low contrast on the status badge, fixed in #12."
  }
}
```

- `include`: selectors for the plugin's own elements. The top-level value is the default for every screen; a screen's own value replaces it. Without either, the whole page is checked.
- `exclude`: selectors to leave out, on top of wp-admin's menu, toolbar, footer, Screen Options and update nags. An element named in `include` is still checked inside them, such as the plugin's own toolbar item.
- `setup` and `cleanup`: run before and after the screen (cleanup also runs when the check fails). Each entry is a WP-CLI command as an array, or `{ "wp": [...] }`, or a function from a helper file next to the spec: `{ "js": "seed.js", "call": "seed", "args": [] }` (the arguments `"@rest"` and `"@request"` pass those fixtures). `"save": "name"` keeps the output for `{name}` in later fields, and `{name.key}` reads a key of a saved object. `{theme}` is the theme the suite runs with. `"mayFail": true` keeps going when a command exits with an error.
- `url`, or `editor` with a post ID to open the block editor with the welcome guide closed. `loggedOut: true` opens the screen as a visitor, and `"dialogs": "accept"` accepts `confirm()` prompts.
- `steps`: `logIn` (a user login, with `password`; uses the `logIn()` helper), `evaluate` (JavaScript run in the page), `hover`, `click`, `fill` with `value`, `press` and `waitFor`, in order. Selectors can be CSS or Playwright selectors such as `role=button[name=/Save/i]` (a regular expression, so the name can carry extra text such as a trailing space); `include` and `exclude` take CSS only.
- `disableRules`: axe rule IDs to skip on that screen, each with the reason as its value.
- `skip`: screens left out for now, with the reason. Only for a violation that a follow-up pull request fixes, and that pull request removes the entry.

### Test helpers

`tests/e2e/utils` holds the helpers every spec shares. Use them instead of writing another copy in a spec.

| Helper | What it does |
| --- | --- |
| `rest( method, route, data )` | Calls the REST API as the logged-in admin, with a nonce |
| `wp( ...args )`, `wpEval( php )` | Runs WP-CLI in the site's `cli` container |
| `openEditor( page, postId )` | Opens the block editor with the welcome guide closed |
| `logIn( page, user, password )` | Signs in through `wp-login.php`. It waits for the login page's own focus timer first, which otherwise moves the password into the username field |
| `logInAs( browser, user, password, options )` | The same in a new browser context with no cookies |
| `appPasswordClient( user, name )` | A REST client that signs in with a new application password and carries no cookies. With the admin's cookie as well, WordPress takes the cookie and refuses the call with a 401 for the missing nonce |
| `expectNoSeriousA11yViolations( page, context )` | Fails on serious or critical axe-core findings in the plugin's own markup (`context`: `name`, `include`, `exclude`, `disableRules`) |

## Plugin Check

`npm run plugin-check` builds `dist/agent-governance/` with `bin/build-zip.sh`, mounts it in the Docker site in place of the source, installs the latest Plugin Check from WordPress.org and runs it the way the CI workflow does. Errors and warnings both fail it. If the site was running, it gets the source folder back at the end.

## Screenshots and demo

`npm run screenshots` and `npm run demo` drive the same Docker site, where this is the only active plugin, so no other plugin's menus or notices end up in the pictures. They refuse to run unless the site has the latest WordPress, because an older one shows the core update nag. Screenshots are saved at twice the viewport size for sharp text. The demo is recorded from Chrome's own frames with a drawn pointer and turned into a GIF by ffmpeg (`brew install ffmpeg` or `apt install ffmpeg`).

## Code layout

Classes live in `src/` under the `AryanX\AgentGovernance\` namespace and follow PSR-4: `AryanX\AgentGovernance\Admin\Foo` is `src/Admin/Foo.php`. The main plugin file registers a small PSR-4 autoloader, so no `vendor/` folder ships with the plugin. Every class that adds hooks implements `Service` and is wired up in `Plugin`.
