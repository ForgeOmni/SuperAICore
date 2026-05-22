# Supply Chain Policy

Borrowed from the pi project's `AGENTS.md` discipline: **treat dependency
changes as reviewed code**.

## Hard rules

1. **No Composer lifecycle scripts in this package.**
   `composer.json` must NOT declare `post-install-cmd`, `post-update-cmd`,
   `pre-*-cmd`, `post-autoload-dump`, `post-package-install`, or
   `post-package-update`. The `supply-chain` workflow enforces this.

2. **`composer install --no-scripts` is the default for any agent run.**
   New dependencies that themselves rely on lifecycle scripts must be
   documented in a PR description and explicitly approved.

3. **Library version ranges are intentional, not lazy.**
   This is a Composer library, so direct deps use caret ranges to allow
   downstream apps (SuperTeam, etc.) to resolve compatible versions.
   Consuming applications are responsible for pinning via their own
   `composer.lock` and reviewing lockfile diffs in PRs.

4. **`composer audit` runs weekly + on every PR.** Findings against direct
   deps block merge until upgraded or formally accepted.

## Dep upgrade checklist

When bumping any direct dependency in `composer.json`:

- [ ] Read the changelog of every minor version between old → new
- [ ] Check the dep's own `composer.json` for new lifecycle scripts
- [ ] Run `composer install --no-scripts` locally
- [ ] Run the full test matrix
- [ ] Note the bump in CHANGELOG.md with the upstream link

## Why

Per pi's contract: a dependency you don't read is a dependency you don't trust.
Lifecycle scripts execute arbitrary code on every install — they are the
single highest-leverage supply-chain attack surface in package managers.
This package's promise to downstream consumers (SuperTeam, third-party
Laravel apps) is that adding `forgeomni/superaicore` to a `composer.json`
will never execute code outside the documented vendor install path.
