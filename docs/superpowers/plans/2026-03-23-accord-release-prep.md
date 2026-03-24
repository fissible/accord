# Accord Release Prep (v1.0.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare `fissible/accord` for public release on Packagist at v1.0.0 by adding release infrastructure, patching `composer.json`, and cutting the v1.0.0 tag locally (push requires manual approval).

**Architecture:** Sequential config/infrastructure tasks — no runtime code changes. All steps 1–6 are local file writes; step 7 runs the interactive `release.sh` script which creates the release commit and tag, then pauses before pushing. Step 8 documents the manual Packagist registration that follows the push.

**Tech Stack:** PHP 8.2/8.3, Composer, PHPUnit 11, GitHub Actions, git-cliff, `release.sh` (fissible release script)

**Spec:** `docs/superpowers/specs/2026-03-23-accord-release-prep-design.md`

---

## Files

| Action | Path | Purpose |
|--------|------|---------|
| Create | `VERSION` | Seed version for `release.sh` to increment from |
| Modify | `composer.json` | Add `homepage`, `authors`; extend `keywords` |
| Create | `.cliff.toml` | git-cliff config required by `release.sh` |
| Create | `release.sh` | Fissible canonical release script |
| Create | `.github/workflows/test.yml` | PHP CI — matrix 8.2 + 8.3 |
| Create | `.github/workflows/release.yml` | Calls fissible reusable release workflow |
| Created by `release.sh` | `CHANGELOG.md` | Auto-generated from conventional commits |

---

## Task 1: Add VERSION file

**Files:**
- Create: `VERSION`

- [ ] **Step 1: Create VERSION containing `0.0.0`**

```bash
echo "0.0.0" > VERSION
```

> **Why `0.0.0`?** `release.sh` *increments* this value. A `major` bump on `0.0.0` produces
> `1.0.0`. Seeding it at `1.0.0` would produce `1.1.0` or higher.

- [ ] **Step 2: Verify**

```bash
cat VERSION
```

Expected output: `0.0.0`

- [ ] **Step 3: Commit**

```bash
git add VERSION
git commit -m "chore: add VERSION file (seed for release.sh)"
```

---

## Task 2: Patch composer.json

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: In `composer.json`, replace the block from `"license"` through `"keywords"` with the expanded version**

Replace this exact block (lines 4–6 of the current file):

```json
    "license": "MIT",
    "keywords": ["openapi", "contract", "validation", "psr-7", "psr-15", "laravel", "slim", "mezzio"],
```

With:

```json
    "license": "MIT",
    "homepage": "https://github.com/fissible/accord",
    "authors": [
        {
            "name": "Allen McCabe",
            "role": "Developer"
        }
    ],
    "keywords": ["openapi", "contract", "validation", "psr-7", "psr-15", "middleware", "laravel", "slim", "mezzio"],
```

This inserts `homepage` and `authors` between `license` and `keywords`, and adds `"middleware"` to the keywords array.

- [ ] **Step 2: Validate the JSON is well-formed**

```bash
composer validate
```

Expected: `./composer.json is valid`

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "chore: add homepage, authors, middleware keyword to composer.json"
```

---

## Task 3: Copy .cliff.toml

**Files:**
- Create: `.cliff.toml`

- [ ] **Step 1: Copy from the fissible org template**

```bash
cp ~/lib/fissible/.github/.cliff.toml .cliff.toml
```

- [ ] **Step 2: Verify it landed**

```bash
head -5 .cliff.toml
```

Expected first line: `# .cliff.toml — git-cliff configuration for fissible projects`

- [ ] **Step 3: Commit**

```bash
git add .cliff.toml
git commit -m "chore: add .cliff.toml for CHANGELOG generation"
```

---

## Task 4: Copy release.sh

**Files:**
- Create: `release.sh`

- [ ] **Step 1: Copy from the fissible org template**

```bash
cp ~/lib/fissible/.github/release.sh release.sh
```

- [ ] **Step 2: Make it executable**

```bash
chmod +x release.sh
```

- [ ] **Step 3: Verify the shebang**

```bash
head -2 release.sh
```

Expected:
```
#!/usr/bin/env bash
# release.sh — fissible standard release script
```

- [ ] **Step 4: Commit**

```bash
git add release.sh
git commit -m "chore: add release.sh (fissible canonical release script)"
```

---

## Task 5: CI workflow

**Files:**
- Create: `.github/workflows/test.yml`

- [ ] **Step 1: Create the workflows directory**

```bash
mkdir -p .github/workflows
```

- [ ] **Step 2: Create `.github/workflows/test.yml` with this exact content**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    name: PHP ${{ matrix.php }}
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3']

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run tests
        run: ./vendor/bin/phpunit
```

> Uses `shivammathur/setup-php@v2` — the standard PHP setup action for GitHub Actions.
> `coverage: none` keeps the run fast (no Xdebug overhead).

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/test.yml
git commit -m "ci: add PHP 8.2/8.3 test workflow"
```

---

## Task 6: Release workflow

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Create `.github/workflows/release.yml` with this exact content**

```yaml
name: Release

on:
  push:
    tags: ['v*']

permissions:
  contents: write

jobs:
  release:
    uses: fissible/.github/.github/workflows/release.yml@main
```

> **Permissions placement is critical.** `permissions: contents: write` must be at the
> **top-level workflow scope** (as shown above), not nested under `jobs:` or `jobs.release:`.
> GitHub only grants the token the required scope when it appears at the top level of the caller.

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "ci: add release workflow (calls fissible reusable workflow)"
```

---

## Task 7: Pre-release verification

No files changed — this is a verification gate before running `release.sh`.

- [ ] **Step 1: Confirm git-cliff is installed**

```bash
command -v git-cliff && git-cliff --version
```

Expected: prints the git-cliff binary path and version. If not found, install it:
```bash
brew install git-cliff
```

- [ ] **Step 2: Confirm all tests still pass**

```bash
vendor/bin/phpunit --no-coverage
```

Expected: `OK (46 tests, 61 assertions)`

- [ ] **Step 3: Confirm composer.json is valid**

```bash
composer validate
```

Expected: `./composer.json is valid`

- [ ] **Step 4: Confirm clean working tree on main**

```bash
git status
git rev-parse --abbrev-ref HEAD
```

Expected: `nothing to commit, working tree clean` and `main`

- [ ] **Step 5: Confirm VERSION is `0.0.0`**

```bash
cat VERSION
```

Expected: `0.0.0`

---

## Task 8: Cut the v1.0.0 release (interactive — pauses before push)

**Files created by script:** `CHANGELOG.md`, updated `VERSION`

`release.sh` is interactive. This task walks through each prompt.

- [ ] **Step 1: Run the release script**

```bash
bash release.sh
```

The script will print the current version (`0.0.0`), last tag (`none`), and a list of all commits.

- [ ] **Step 2: At the bump prompt, type `major`**

The script will suggest `minor`. This is a false positive: commit `3d1e474` contains the word
"feature" which matches `release.sh`'s ` feat` substring pattern — there are no actual `feat:`
conventional commits in the history. Regardless of the reason, you must override to `major`
to produce `1.0.0`:

```
Suggested bump: minor
Bump type [patch/minor/major, default: minor]: major
```

Type: `major` then Enter.

- [ ] **Step 3: Confirm the new version**

The script will show:
```
New version: 0.0.0 → 1.0.0

Proceed? [y/N]
```

Type: `y` then Enter.

- [ ] **Step 4: Let the script run**

The script will:
1. Write `1.0.0` to `VERSION`
2. Run `git-cliff` to generate `CHANGELOG.md`
3. Run `git add VERSION CHANGELOG.md`
4. Create commit `chore: release v1.0.0`
5. Create annotated tag `v1.0.0`

Then it will print: `Tagged v1.0.0 locally.`

- [ ] **Step 5: At the push prompt, type `n` (pause for review)**

```
Push to origin? [y/N]
```

Type: `n` then Enter.

The script prints:
```
Skipped push. When ready:
  git push && git push --tags
```

- [ ] **Step 6: Review the generated CHANGELOG.md**

```bash
cat CHANGELOG.md
```

Verify it contains a `## [1.0.0]` section with entries from the commit history.

- [ ] **Step 7: Review the release commit and tag**

```bash
git log --oneline -5
git tag -l
```

Expected: `v1.0.0` appears in the tag list. The most recent commit is `chore: release v1.0.0`.

---

## Task 9: Push (requires your approval)

This task is intentionally left as a manual step. When you're satisfied with the CHANGELOG and tag:

- [ ] **Step 1: Push branch and tags**

```bash
git push && git push --tags
```

This triggers the GitHub Release workflow (`.github/workflows/release.yml`), which creates a GitHub Release with the v1.0.0 CHANGELOG section as the body.

- [ ] **Step 2: Verify the GitHub Release was created**

```bash
gh release view v1.0.0
```

Expected: release body contains the v1.0.0 CHANGELOG section.

- [ ] **Step 3: Verify CI passes**

```bash
gh run list --limit 5
```

Check that the CI run triggered by the push is passing on PHP 8.2 and 8.3.

---

## Task 10: Packagist (manual — browser steps)

This cannot be automated. Complete these steps after the GitHub Release exists.

- [ ] **Step 1: Submit the package to Packagist**

1. Go to [packagist.org](https://packagist.org) and log in
2. Click **Submit** in the top nav
3. Enter `https://github.com/fissible/accord` and click **Check**
4. Click **Submit** to publish the package

- [ ] **Step 2: Add the GitHub webhook for auto-updates**

Packagist shows a webhook URL after submission. Add it to GitHub:
1. Go to `https://github.com/fissible/accord/settings/hooks`
2. Click **Add webhook**
3. Paste the Packagist webhook URL as the **Payload URL**
4. Set **Content type** to `application/json`
5. Select **Just the push event** (or "Let me select individual events" → Pushes + Releases)
6. Click **Add webhook**

- [ ] **Step 3: Verify the package resolves**

After a minute or two for Packagist to index:

```bash
composer require fissible/accord:^1.0 --dry-run
```

Expected: resolves `fissible/accord v1.0.0` without errors.

---

## Done criteria

- [ ] `composer require fissible/accord` resolves v1.0.0 from Packagist
- [ ] CI passing on PHP 8.2 + 8.3
- [ ] v1.0.0 annotated tag + GitHub Release exist
- [ ] `CHANGELOG.md` present with v1.0.0 entry
