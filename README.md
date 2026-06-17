# fissible/accord

**Catch API contract violations the moment they happen — in development, in your tests, and in production.**

accord checks every HTTP request and response your API handles against an OpenAPI spec, at runtime. It's a PSR-7/15 package with first-party drivers for **Laravel**, **Slim**, and **Mezzio**, and a framework-agnostic core you can wire into anything.

```bash
composer require fissible/accord
```

---

## What problem does this solve?

Every API makes a promise to the apps and teams that depend on it: *send me this shape of data, and I'll return that shape of data.* That promise is the **contract**. When it quietly breaks — a field goes missing, a type changes, a list becomes an object — the clients depending on your API fail, often in ways that are hard to trace and expensive to fix.

An **OpenAPI spec** is a standard YAML or JSON file that writes that promise down: for each endpoint, what it accepts (query/path/header parameters and a request body) and what it returns (status codes and response bodies). It's the single source of truth for your API's shape.

**accord holds your API to that spec automatically.** You point it at your spec file, and it validates traffic against it in real time — catching a violation the instant it happens instead of when a downstream client breaks. You can use it two ways, and most teams use both:

- **As middleware** — every request and response flowing through your app is validated live. How a violation is handled is up to you: throw an error, log a warning, or hand it to your own callback.
- **As a test assertion** — in your feature tests, assert that a response matches the contract, so a drifting API fails CI before it ships.

accord **fails open by design**: anything the spec doesn't describe (an undocumented route, a missing schema) passes through untouched. It only ever enforces what your spec actually says — which makes it safe to bolt onto an API that's already running, and adopt one endpoint at a time.

---

## Quick start (Laravel)

The service provider auto-registers via package discovery, so getting to "it's validating" takes about five minutes.

**1. Install.**

```bash
composer require fissible/accord
```

**2. Get a spec.** Drop an OpenAPI 3.0 file at `resources/openapi/v1.yaml`. Don't have one? Generate it from your existing routes with [fissible/forge](https://github.com/fissible/forge):

```bash
composer require --dev fissible/forge
php artisan accord:generate --title="My API"
```

This documents every route and infers request-body schemas from your FormRequest rules. (Response schemas come through as empty objects — fill those in to describe what your endpoints actually return.)

**3. Add the middleware** to your API routes. In Laravel 11+ `bootstrap/app.php`:

```php
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;

->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', ValidateApiContract::class);
})
```

**4. Start in `log` mode** so violations surface without breaking anything. In `.env`:

```dotenv
ACCORD_FAILURE_MODE=log
```

That's it. Hit your API and any request or response that doesn't match the spec is logged as a warning. Review those, fix the gaps (in your spec *or* your API), then flip to enforcing — see [Setting it up properly](#setting-it-up-properly) below.

---

## What accord can do

A tour of the capabilities, each linking to the detail:

- **Validates requests** — query, path, and header parameters *and* the JSON body — against the matched operation. ([How it works](#how-it-works))
- **Validates responses** — status code and body — against the spec.
- **Fails open, safe to adopt** — undocumented routes and schemas pass untouched; you enforce only what the spec describes.
- **Failure modes you choose** — `exception` (block), `log`, or a `callable`; and [different modes per direction](#per-direction-failure-modes) (e.g. reject bad requests but only log bad responses).
- **HTTP-aware errors** — in Laravel, a bad *request* is rendered as a real `422` with a JSON error body, not an opaque `500`. ([Per-direction failure modes](#per-direction-failure-modes))
- **Diagnostics** — see exactly what was validated versus silently skipped, and why. ([Diagnostics](#diagnostics))
- **Production controls** — [exclude routes, sample responses](#running-it-in-production), and [cache the parsed spec](#caching-the-spec) so it's cheap to leave on.
- **Lenient matching** — [wildcard media types and `servers` base paths](#path--content-type-matching), plus array query parameters in every common form.
- **Beyond Laravel** — [Slim](#slim) and [Mezzio](#mezzio) drivers, and a framework-agnostic core you can [drive yourself](#extending).
- **A CI drift gate** — pair with [fissible/drift](#the-fissible-suite) to fail the build when your routes drift from the spec.

---

## Requirements

- PHP `^8.2`
- OpenAPI 3.0.x spec files (YAML or JSON)

## Installation

```bash
composer require fissible/accord
```

The Laravel service provider registers automatically. To customise configuration, publish the config file:

```bash
php artisan vendor:publish --tag=accord-config
```

---

## How it works

For each request (and its response), accord:

1. **Extracts the API version** from the URI path — `/v1/users` → `v1`. (Configurable; see [Version extraction](#version-extraction).)
2. **Loads the matching spec** — `resources/openapi/v1.yaml`. Specs are parsed once per version per process and cached in memory.
3. **Finds the operation** for the request's method and path, then **validates** the request parameters and body, and the response status and body, against the schemas the spec defines.

If there's no version in the path, no spec for that version, no matching operation, or no schema for the content type, accord **passes it through unvalidated** — it enforces only what the spec describes. (Turn on [diagnostics](#diagnostics) to see when and why that happens.)

---

## Setting it up properly

The recommended path to enforcing your contract without surprises:

1. **Adopt in `log` mode.** `ACCORD_FAILURE_MODE=log` surfaces every violation as a PSR-3 warning while letting all traffic through. Run it in staging (or production) and collect the real violations.
2. **Close the gaps.** Each logged violation is either a spec that doesn't match reality, or an endpoint that doesn't match its spec. Fix whichever is wrong.
3. **Turn on enforcement.** Once the log is quiet, switch to `ACCORD_FAILURE_MODE=exception`. A common production posture is to **reject bad requests but only log bad responses** — see [Per-direction failure modes](#per-direction-failure-modes).
4. **Confirm it's actually validating.** Because accord fails open, "no violations" can mean "everything's compliant" *or* "nothing was checked." Use [diagnostics](#diagnostics) and the [`assertResponseWasValidated`](#testing-your-api-against-the-contract) test assertion to be sure.
5. **Keep it honest in CI.** Add [fissible/drift](#the-fissible-suite) so new routes that drift from the spec fail the build before they merge. ([Continuous integration](#continuous-integration))

---

## Configuration

The published `config/accord.php` (every key has an `env()` default, so you can drive it entirely from `.env`):

```php
return [
    'failure_mode'   => env('ACCORD_FAILURE_MODE', 'exception'), // exception | log | callable
    'log_channel'    => env('ACCORD_LOG_CHANNEL'),               // null = default logger
    'request_violation_status' => env('ACCORD_REQUEST_VIOLATION_STATUS', 422), // request 4xx; non-4xx → 422
    'debug'          => env('ACCORD_DEBUG', false), // log skipped (non-validated) requests/responses + why
    'exclude'              => [],                                  // glob patterns; matched routes skip all validation
    'validate_responses'   => env('ACCORD_VALIDATE_RESPONSES', true),    // false = don't validate responses (requests still validated)
    'response_sample_rate' => env('ACCORD_RESPONSE_SAMPLE_RATE', 1.0),   // fraction of responses to validate (0.0–1.0)
    'failure_callable' => null,
    'version_pattern'  => '/^\/v(\d+)(?:\/|$)/',
    'spec_source'    => env('ACCORD_SPEC_SOURCE', 'file'),       // file | url
    'spec_pattern'   => env('ACCORD_SPEC_PATTERN', '{base}/resources/openapi/{version}'),
    'spec_cache'     => env('ACCORD_SPEC_CACHE', null),         // null|true|'store' — persist the parsed spec
    'spec_cache_ttl' => env('ACCORD_SPEC_CACHE_TTL', 3600),
];
```

The rest of this section explains the knobs that need more than a one-line comment.

### Failure modes

How a contract violation is handled:

| Mode        | Behaviour |
|-------------|-----------|
| `exception` | Throws `ContractViolationException` (default) |
| `log`       | Logs a `warning` via PSR-3; the request continues |
| `callable`  | Calls your callable with the `ValidationResult`; the request continues |

The `callable` mode lets you route violations anywhere:

```php
// config/accord.php
'failure_mode'     => 'callable',
'failure_callable' => function (\Fissible\Accord\ValidationResult $result): void {
    // report to your error tracker, queue a job, send an alert, etc.
    \Sentry\captureMessage(implode(', ', $result->errors));
},
```

### Per-direction failure modes

`failure_mode` can be a single value applied to both directions, or an array with separate `request` and `response` modes:

```php
'failure_mode' => ['request' => 'exception', 'response' => 'log'],
```

In the Laravel driver, a **request** violation under `exception` mode is rendered as a JSON response (`{ "message": ..., "errors": [...] }`) with `request_violation_status` (default `422`; a non-4xx value falls back to `422`) — a client's bad request gets a real `422`, not a `500`. A **response** violation is a server-side problem: under `exception` mode it surfaces as a `500`, and under `log` mode it's logged while the original response passes through unchanged — it is never rendered as a 4xx.

### Diagnostics

Because accord fails open, a missing spec, unmatched route, or undeclared schema all pass — which can hide the fact that nothing actually ran. Two tools make that visible:

- Set `ACCORD_DEBUG=true` to log, at `debug` level, every request and response accord **skipped** and why (`missing_spec`, `unmatched_operation`, `missing_request_schema`, `missing_response_schema`, `unsupported_media_type`, `unversioned`, `excluded`, `response_validation_disabled`, `not_sampled`). It's off by default and free when off — turn it on while diagnosing, not in steady state.
- On any `ValidationResult`, `wasValidated()` is `true` only when the request/response was actually checked (a pass *or* a genuine failure); `wasSkipped()` and `$result->skipReason` tell you which fail-open branch was taken.
- In Laravel feature tests, [`assertResponseWasValidated($response)`](#testing-your-api-against-the-contract) fails (naming the skip reason) if the response was silently skipped.

> **Note:** with `validate_responses` off (or a very low sample rate) *and* `ACCORD_DEBUG` on, debug logs *every* response as a skip — expected for a diagnostic mode, but don't run that combination in steady state.

### Running it in production

Response validation runs on every response by default. Three knobs keep it cheap on high-traffic or large-payload APIs:

- **`exclude`** — glob patterns (`*` matches any characters, including `/`). Matched routes skip *all* validation, request and response (e.g. `['/v2/health', '/v2/internal/*', '*/metrics']`). Cost: those routes aren't contract-checked at all.
- **`validate_responses => false`** — stop validating responses while still validating requests. Cost: response drift goes uncaught at runtime — rely on the `AssertsApiContracts` CI checks instead.
- **`response_sample_rate`** — validate only a fraction of responses (e.g. `0.1` ≈ 10%). Trades coverage for throughput; out-of-range values are clamped to `0.0..1.0`.

These show up in `ACCORD_DEBUG` output as `excluded`, `response_validation_disabled`, and `not_sampled` skips.

### Caching the spec

`FileSpecSource` parses the OpenAPI file on every `load()`, and in PHP-FPM each request is a fresh process — so the (slow) YAML parse runs per request. Enable a persistent cache to parse once and rehydrate from cached JSON on subsequent requests (roughly an order of magnitude faster):

- **`spec_cache`** — `null`/`false` = off (in-process cache only; the default), `true` = the application's default cache store, or a store name (e.g. `'redis'`). The resolved cache is wired into both file and URL sources.
- **Invalidation is automatic for files:** the cache key includes the spec file's modification time, so a redeployed/edited spec produces a new key and is re-parsed — no `cache:clear` needed. `spec_cache_ttl` is just a backstop that evicts stale old-mtime entries.

Two caveats:

- **Long-lived workers (Octane/RoadRunner):** the in-process parsed spec lives for the life of the worker, so mtime invalidation only helps fresh processes (PHP-FPM). Restart workers on deploy (these stacks already do) to pick up a changed spec.
- **External `$ref`s:** the cache stores the spec's *serialized data*, so specs that rely on **external-file** `$ref`s may not round-trip — keep specs self-contained. Internal `#/components` refs are fine.

For Slim/Mezzio, pass a PSR-16 cache instance directly: `AccordFactory::make(['spec_cache' => $psr16, ...], $basePath)`.

### Loading specs from a URL

Set `spec_source` to `url` and provide a pattern with a `{version}` token:

```dotenv
ACCORD_SPEC_SOURCE=url
ACCORD_SPEC_PATTERN=https://api.example.com/openapi/{version}.yaml
```

Useful when specs are managed externally or shared across services. Fetched specs are cached in memory per process; enable [`spec_cache`](#caching-the-spec) to persist them across restarts (recommended for serverless).

### Path & content-type matching

accord matches the request path against your spec's path templates as-is first. If nothing matches, it also tries stripping each root-level `servers` base path — so a spec with `servers: [{url: /v2}]` and a relative path `/users` matches a request to `/v2/users`. (Stripping is segment-safe: `/v20/...` is not treated as under `/v2`. Path-item/operation-level `servers` overrides are not considered, and the API version must still appear in the request path or be matched by your `version_pattern`.)

Content types are matched exact-first, then by wildcard: a request/response `application/json` matches a spec that declares `application/*` or `*/*` (exact declarations always win).

### Version extraction

By default the version is taken from the URI path:

| URI | Extracted version | Spec file |
|-----|-------------------|-----------|
| `/v1/users` | `v1` | `resources/openapi/v1.yaml` |
| `/v2/orders/99` | `v2` | `resources/openapi/v2.yaml` |
| `/users` | _(none — passes unconstrained)_ | — |

Change the pattern via `version_pattern`; capture group 1 must match the version number.

### Spec files

Place your OpenAPI 3.0 specs at:

```
resources/openapi/v1.yaml   ← preferred (hand-authored)
resources/openapi/v2.yaml
```

JSON is also supported. When the path pattern has no extension, accord tries `.yaml`, `.yml`, and `.json` in that order.

---

## Testing your API against the contract

The same validation, available as a test assertion — so a drifting API fails CI. Add the `AssertsApiContracts` trait and call it after any API request:

```php
use Fissible\Accord\Drivers\Laravel\Testing\AssertsApiContracts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase, AssertsApiContracts;

    public function test_index_matches_contract(): void
    {
        $response = $this->getJson('/v1/users');

        $response->assertOk();
        $this->assertResponseMatchesContract($response);   // body matches the spec
        $this->assertResponseWasValidated($response);      // ...and it actually WAS validated (not silently skipped)
    }
}
```

`assertResponseMatchesContract` fails if the response violates the spec. `assertResponseWasValidated` fails (naming the skip reason) if the response was silently skipped — pair them to avoid "green because nothing validated."

---

## Slim

```php
use Fissible\Accord\Drivers\Slim\AccordMiddleware;

$app->add(AccordMiddleware::fromConfig([
    'failure_mode' => 'log',
    'spec_pattern' => '{base}/openapi/{version}',
], __DIR__));
```

Or use the core middleware directly if you're wiring the validator yourself:

```php
use Fissible\Accord\AccordMiddleware;

$app->add(new AccordMiddleware($validator));
```

## Mezzio

```php
// config/pipeline.php
use Fissible\Accord\Drivers\Mezzio\AccordMiddleware;

$app->pipe(AccordMiddleware::fromConfig([
    'failure_mode' => 'exception',
], __DIR__));
```

Or register via your container:

```php
// config/autoload/accord.global.php
return [
    'dependencies' => [
        'factories' => [
            AccordMiddleware::class => fn() => AccordMiddleware::fromConfig(
                $config['accord'] ?? [],
                __DIR__ . '/../..',
            ),
        ],
    ],
];
```

---

## Extending

### Custom spec sources

`FileSpecSource` (local files) and `UrlSpecSource` (remote) ship in the box. For anything else — a database, a registry, an internal API — implement `SpecSourceInterface`:

```php
use Fissible\Accord\SpecSourceInterface;
use cebe\openapi\spec\OpenApi;

class RemoteSpecSource implements SpecSourceInterface
{
    public function load(string $version): ?OpenApi { /* ... */ }
    public function exists(string $version): bool   { /* ... */ }
}
```

For reference, the built-in file and URL sources:

```php
use Fissible\Accord\FileSpecSource;
use Fissible\Accord\UrlSpecSource;

// Local files — pattern omits the extension; .yaml/.yml/.json are tried in order.
$file = new FileSpecSource('/var/www/app', '{base}/resources/openapi/{version}');

// Remote URL — with an optional PSR-16 cache for persistence.
$url = new UrlSpecSource(
    pattern: 'https://specs.example.com/openapi/{version}.yaml',
    cache:   $psrCache,   // optional PSR-16
    ttl:     3600,
);
```

### Custom framework drivers

To integrate accord with a framework not covered by the bundled drivers, implement `DriverInterface`:

```php
use Fissible\Accord\DriverInterface;
use Fissible\Accord\FailureMode;

class MyFrameworkDriver implements DriverInterface
{
    public function resolveSpecPath(string $version): string
    {
        return sprintf('/path/to/specs/%s.yaml', $version);
    }

    public function getFailureMode(): FailureMode
    {
        return FailureMode::Exception;
    }

    public function getFailureCallable(): ?callable
    {
        return null;
    }
}
```

---

## The Fissible suite

accord is the foundation of a family of focused PHP packages for keeping your API and its documentation honest with each other. You only need accord for runtime validation — the rest are optional companions you add as your needs grow.

```
  [forge]  ──────────────────────────────►  [accord]  ◄── [watch] ◄── [fault]
  generate / update spec                   validate at      cockpit UI   exception
      ▲                                    runtime │        (bolt-on)    tracking
      │                                            ▼
      └──────────────────────────────────  [drift]
                                           detect drift, bump version
```

- **[fissible/forge](https://github.com/fissible/forge)** — scaffolds an OpenAPI spec from your existing routes, inferring request-body schemas from your FormRequest rules. *(Standalone — no suite dependencies.)*
- **fissible/accord** ← you are here — the runtime enforcer. Validates every request and response against the spec. *(Foundation — no suite dependencies.)*
- **[fissible/drift](https://github.com/fissible/drift)** — detects when the routes your app serves have drifted from the spec, recommends a semver bump, and generates a changelog entry. *(Depends on accord.)*
- **[fissible/watch](https://github.com/fissible/watch)** — a Telescope-style bolt-on cockpit (route browser, drift detector, spec manager, API explorer) mounted at `/watch`. *(Depends on accord + drift + forge.)*
- **[fissible/fault](https://github.com/fissible/fault)** — exception tracking and triage for the watch cockpit. *(Depends on watch.)*

---

## Continuous integration

Pair accord with [fissible/drift](https://github.com/fissible/drift) to turn contract drift into a build failure. `accord:validate` exits non-zero when the app's routes have drifted from the spec (a route added but undocumented, or removed but still in the spec):

```bash
composer require --dev fissible/drift
php artisan accord:validate                    # check for drift locally
php artisan accord:validate --api-version=v1   # or pin to one version
```

### GitHub Actions

```yaml
name: API contract

on: [push, pull_request]

jobs:
  contract:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_sqlite
      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan migrate --force
      - name: Check API contract (drift)
        run: php artisan accord:validate
      - name: Check implementation coverage
        run: php artisan drift:coverage
```

`drift:coverage` is an optional second check — it verifies every registered route has a real controller (not just a closure), catching skeleton routes that were never wired up.

### GitLab CI

```yaml
contract:
  stage: test
  image: php:8.3-cli
  before_script:
    - composer install --no-interaction --prefer-dist
    - cp .env.example .env
    - php artisan key:generate
    - php artisan migrate --force
  script:
    - php artisan accord:validate
    - php artisan drift:coverage
```

---

## License

MIT
