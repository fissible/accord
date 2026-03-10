# CLAUDE.md — fissible/accord

## What this is

The foundational package of the Fissible suite. A PSR-7/15 middleware that validates HTTP requests and responses against an OpenAPI 3.0 spec at runtime. No framework required in the core — Laravel, Slim, and Mezzio drivers live in `src/Drivers/`.

## Running tests

```bash
vendor/bin/phpunit
```

Two suites: `Unit` and `Feature`. All tests are in `tests/`.

## Key files

| File | Purpose |
|---|---|
| `src/ContractValidator.php` | Core engine — loads spec, validates request/response against it |
| `src/AccordMiddleware.php` | PSR-15 middleware wrapping the validator |
| `src/ValidationResult.php` | Immutable result: `valid bool` + `errors string[]` |
| `src/FailureMode.php` | Enum: `Exception \| Log \| Callable` |
| `src/SpecSourceInterface.php` | Contract for spec loading — implement to load from anywhere |
| `src/FileSpecSource.php` | Loads YAML/JSON specs from the local filesystem |
| `src/UrlSpecSource.php` | Fetches specs from a remote URL with optional PSR-16 cache |
| `src/VersionExtractor.php` | Extracts API version string from a URI path |
| `src/AccordFactory.php` | Factory for constructing a `ContractValidator` from config |
| `src/DriverInterface.php` | Framework driver contract (spec path resolution + failure mode) |
| `src/Drivers/Laravel/` | Laravel-specific service provider, middleware, and test trait |

## Architecture rules

**The core has no framework dependency.** `src/` (excluding `src/Drivers/`) must not import anything from `illuminate/`, `slim/`, or `laminas/`. Framework code belongs exclusively in the relevant `src/Drivers/` subdirectory.

**Specs are cached in memory per process.** `ContractValidator` caches loaded `OpenApi` objects keyed by version. Don't bypass this — loading YAML is expensive.

**`ValidationResult` is immutable.** Create new instances via the static constructors; don't add setters.

## Conventions

- `declare(strict_types=1)` on every file
- No `public` properties — use readonly constructor promotion or getters
- Failure handling is controlled by `FailureMode` enum — don't add conditional logic outside it
- Test fixtures (spec YAML files) live in `tests/Fixtures/` (currently empty; add there, not inline)

## Adding a new driver

1. Create `src/Drivers/{Framework}/` subdirectory
2. Implement `DriverInterface` for framework-specific config
3. Wrap `AccordMiddleware` (the PSR-15 class) or adapt it as needed
4. Register a service provider / container binding in the driver directory
5. Add a section to the README

## Relationship to other packages

- **fissible/drift** depends on accord for `SpecSourceInterface`, `FileSpecSource`, and the `OpenApi` object graph
- **fissible/forge** depends on accord for the same spec source abstractions
- **fissible/pilot** installs all three and wires them together in a Laravel app
