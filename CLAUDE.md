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

# Tome Context Store
This project uses Tome (`.tome.db`) for structured context.
- Before responding, extract topic keywords from the user's message and call `tome_lookup`.
- If another project is mentioned by name, also call `tome_cross_lookup`.
- When you learn a durable truth about this project (architectural decisions, conventions,
  gotchas, dependency relationships), call `tome_store` to save it.
- Prefer `kind='decision'` for choices with rationale, `kind='gotcha'` for non-obvious
  pitfalls, `kind='convention'` for patterns to follow, and `kind='fact'` for everything
  else (including dependency relationships).
- For gotchas and dependency facts, save automatically. For decisions, conventions,
  and architectural facts, ask the user first.
- When a fact from Tome directly helps answer the user's question, call
  `tome_rate(fact_id, useful=True)`. If a fact was misleading or wrong, call
  `tome_rate(fact_id, useful=False, reason="...")`. Verified incorrect facts are
  deleted automatically.
- To inspect structured tabular data, use `tome_query_dataset(name)`.
- During idle periods (no user prompt for 2+ minutes), call `tome_dream()` to run
  one maintenance cycle. Review the returned batch and rate/delete facts as needed.
  Stop dreaming when the user sends a new prompt.

## Code navigation
Before reading any source file, use cymbal to find what you need:
- `cymbal search <name>` — find a symbol by name (avoids reading whole files)
- `cymbal show <symbol>` — read a function or class body directly
- `cymbal outline <file>` — see all definitions in a file before opening it
- `cymbal impact <symbol>` — find callers before changing a function
- `cymbal trace <symbol>` — see what a function calls

Only fall back to reading files directly when cymbal cannot answer the question.
