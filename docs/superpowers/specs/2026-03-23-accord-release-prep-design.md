# fissible/accord — Release prep design (v1.0.0)

**Date:** 2026-03-23
**Issue:** [#1 — Release prep: VERSION, CI, and Packagist](https://github.com/fissible/accord/issues/1)
**Approach:** Canonical fissible release flow (Approach A — `release.sh` owns the CHANGELOG)

---

## Goal

Prepare `fissible/accord` for public release on Packagist at v1.0.0.

All 46 tests currently pass. Nothing is blocked technically. The only missing pieces are release
infrastructure and a small `composer.json` patch.

---

## Steps (ordered, sequential)

### 1. `VERSION` file
Add `VERSION` at repo root containing `0.0.0`.

> **Why not 1.0.0?** `release.sh` *increments* the current VERSION value. Starting at `0.0.0`
> with a `major` bump produces the target `1.0.0`. Starting at `1.0.0` would produce `1.1.0`
> (or `1.0.1`/`2.0.0` depending on bump type).

### 2. `composer.json` patch
Add two missing fields:
- `"homepage": "https://github.com/fissible/accord"`
- `"authors": [{"name": "Allen McCabe", "role": "Developer"}]`

Extend `keywords` to merge the issue's list with the existing accurate set:
`["openapi", "contract", "validation", "psr-7", "psr-15", "middleware", "laravel", "slim", "mezzio"]`

### 3. `.cliff.toml`
Copy `~/lib/fissible/.github/.cliff.toml` to repo root. Required by `release.sh`.

### 4. `release.sh`
Copy `~/lib/fissible/.github/release.sh` to repo root. The canonical fissible release script.

### 5. CI workflow — `.github/workflows/test.yml`
- Trigger: `push` and `pull_request` on `main`
- Matrix: PHP 8.2, 8.3
- Steps: `composer install --no-interaction --prefer-dist` → `./vendor/bin/phpunit`
- Custom workflow (not the bash test reusable) because this is a PHP project

### 6. Release workflow — `.github/workflows/release.yml`
Use this exact structure (from the fissible org README):

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

`permissions: contents: write` must be at the **top-level workflow scope**, not under `jobs:`.
The reusable workflow already declares its own permissions internally; the caller's top-level
block ensures GitHub grants the token the required scope.

### 7. Run `bash release.sh`
The script will:
1. Verify on `main` with a clean working tree
2. Show all commits since last tag
3. Suggest a `minor` bump (due to `feat` commits; no breaking changes in history)
   → **override to `major`** to produce the target `1.0.0`
4. Write `VERSION` to `1.0.0` (incremented from `0.0.0` via `major` bump)
5. Generate `CHANGELOG.md` via git-cliff
6. Commit `chore: release v1.0.0`
7. Create annotated tag `v1.0.0`
8. **Pause before push** — user reviews and approves before anything is sent to GitHub

### 8. Packagist (manual — out of scope for automation)
After push and GitHub Release creation:
1. Go to packagist.org, log in, click "Submit"
2. Enter `https://github.com/fissible/accord` and submit
3. In GitHub repo Settings → Webhooks, add the Packagist webhook URL for auto-updates

---

## Stopping point

Step 7 ends with a review pause before `git push`. Nothing is pushed to GitHub until the user
explicitly approves in the terminal. Steps 1–6 are all local, reversible file changes.

---

## Done criteria (from issue)

- [ ] `composer require fissible/accord` resolves v1.0.0 from Packagist
- [ ] CI passing on PHP 8.2 + 8.3
- [ ] v1.0.0 annotated tag + GitHub Release exist
- [ ] `CHANGELOG.md` present with v1.0.0 entry
