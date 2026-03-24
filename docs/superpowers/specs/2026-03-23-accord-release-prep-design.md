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
Add `VERSION` at repo root containing `1.0.0`.

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
- Calls `fissible/.github/.github/workflows/release.yml@main`
- Must include `permissions: contents: write`
- Trigger: tag push matching `v*`

### 7. Run `bash release.sh`
The script will:
1. Verify on `main` with a clean working tree
2. Show all commits since last tag
3. Suggest a `minor` bump → confirm `v1.0.0`
4. Write `VERSION` to `1.0.0`
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
