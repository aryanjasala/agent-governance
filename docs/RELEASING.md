# Releasing

Releases are automatic. Nobody bumps a version or pushes a tag by hand.

1. **A pull request is merged.** The **Prepare release** workflow reads the commits since the last tag. If any are `feat`, `fix`, `perf` or breaking, it opens (or updates) a pull request titled `chore(release): x.y.z` that:
   - bumps the version in the plugin header, the `VERSION` constant and the `readme.txt` `Stable tag`
   - adds a `= x.y.z =` entry to the `readme.txt` changelog, one line per commit
2. **The release pull request is merged.** The **Release** workflow sees a version on `main` that has no tag yet. It runs PHPCS and PHPUnit, builds the zip with `bin/build-zip.sh`, tags `vx.y.z` and publishes the GitHub release with the changelog entry as notes.

The release pull request stays open and keeps updating as more pull requests land, so several fixes can ship in one release. Its checks wait for approval, because GitHub Actions opened it, so Prepare release approves them itself.

The release notes carry the changelog entry, an install command, the SHA-256 of the zip and a link to the full diff since the last release.

## Locally

```sh
bin/release.sh next    # the version the next release would get, and why
bin/release.sh notes   # the changelog lines it would add
```

## WordPress.org

The Release workflow can also deploy to the WordPress.org plugin directory. It is off until the repository variable `WPORG_DEPLOY` is set to `true` and the secrets `SVN_USERNAME` and `SVN_PASSWORD` hold the WordPress.org account. The deploy takes the screenshots and banners from `.wordpress-org/`.
