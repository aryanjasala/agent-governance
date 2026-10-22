# Contributing

## Pull requests only

`main` is protected. Every change goes through a pull request, and it can only be merged once these pass:

| Check | What it runs |
| --- | --- |
| WordPress coding standards | PHPCS |
| Static analysis | PHPStan level 6 |
| PHPUnit (PHP 7.4 to 8.4) | The unit tests on six PHP versions |
| Playwright (WordPress latest) | The end-to-end suite against the latest WordPress in Docker |
| WordPress.org Plugin Check | The official Plugin Check, on the zip that would ship |
| Conventional PR title | The title format below |

The branch also has to be up to date with `main`, conversations have to be resolved, and force pushes are refused.

## PR titles

Pull requests are squash-merged, so **the PR title becomes the commit on `main`** and decides the next release. Use a [Conventional Commit](https://www.conventionalcommits.org/) title:

| Title | Release |
| --- | --- |
| `feat: add bulk restore` | minor, for example 1.1.0 to 1.2.0 |
| `fix: keep the notice on narrow screens` or `perf: ...` | patch, for example 1.1.0 to 1.1.1 |
| `feat!: drop PHP 7.4`, or a `BREAKING CHANGE:` footer | major, for example 1.1.0 to 2.0.0 |
| `docs:`, `ci:`, `chore:`, `test:`, `refactor:`, `build:`, `style:` | no release |

Write the description as the changelog line you want: `fix: keep the trash link visible on narrow screens` becomes `* Fix: keep the trash link visible on narrow screens` in `readme.txt`.

## Before opening one

```sh
composer lint && composer analyse && composer test
npm run env:start && npm run test:e2e
```

Add or update a test for any behavior change, and run `npm run screenshots` if the change is visible in a screenshot.
