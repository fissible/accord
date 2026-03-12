# fissible/accord

OpenAPI contract validation for PHP. PSR-7/15 core with first-party drivers for Laravel, Slim, and Mezzio.

**Start here.** accord is the foundation of the Fissible suite — the other packages build on top of it.

---

## The Fissible suite

Fissible is a family of focused PHP packages for keeping your API and its documentation honest with each other.

```
  [forge]  ──────────────────────────────►  [accord]  ◄── [watch]
  generate / update spec                   validate at      bolt-on cockpit UI
      ▲                                    runtime │        (requires all three)
      │                                            ▼
      └──────────────────────────────────  [drift]
                                           detect drift, bump version
```

The core three (forge → accord → drift) form a continuous loop. **watch** is a paid bolt-on that mounts a live cockpit UI over all three in any Laravel application.

### [fissible/forge](https://github.com/fissible/forge)

Scaffolds an OpenAPI spec from your existing routes, inferring request body schemas from your FormRequest validation rules. Use it to get started with a spec, and again whenever a new API version needs documenting.

**Depends on:** nothing from the suite (standalone spec generator)

### fissible/accord ← you are here

The runtime enforcer. Validates every request and response against the spec in real time. Lives in your application permanently — the spec it validates against evolves, but accord itself stays put.

**Depends on:** nothing from the suite (foundation package)

### [fissible/drift](https://github.com/fissible/drift)

Detects when the routes your application actually serves have drifted from what the spec describes. Recommends a semver bump, generates a changelog entry, and closes the loop — signalling that it's time to update the spec.

**Depends on:** accord (reads specs via SpecSourceInterface)

### [fissible/watch](https://github.com/fissible/watch) — paid

A Telescope-style bolt-on that mounts a live cockpit dashboard, route browser, drift detector, spec manager, and API explorer at `/watch` in any existing Laravel application.

**Depends on:** accord + drift + forge (requires all three)

---

## Integrating with an existing Laravel API

accord is the only package you need to install for runtime validation. forge and drift are optional companions you can add as your needs grow.

### Step 1 — Install accord

```bash
composer require fissible/accord
```

The service provider registers automatically via Laravel's package discovery.

### Step 2 — Get a spec

**Don't have a spec yet?** scaffold one from your existing routes with [fissible/forge](https://github.com/fissible/forge):

```bash
composer require --dev fissible/forge
php artisan accord:generate --title="My API"
```

This writes `resources/openapi/v1.yaml` with every route documented and request body schemas inferred from your FormRequest classes. Response schemas are scaffolded as empty objects — you fill those in to describe what your API actually returns.

**Already have a spec?** Drop it at `resources/openapi/v1.yaml` (or configure a different path — see [Spec files](#spec-files) below).

### Step 3 — Register the middleware

Add the middleware to your API route group. For a new Laravel 11+ app, the cleanest place is `bootstrap/app.php`:

```php
use Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract;

->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', ValidateApiContract::class);
})
```

Or scope it to a specific route group in `routes/api.php`:

```php
Route::middleware(ValidateApiContract::class)->group(function () {
    require __DIR__ . '/api/v1.php';
});
```

### Step 4 — Choose a failure mode for adoption

If you're adopting accord on an API that has been running without a spec, start with `log` mode so violations surface as warnings without breaking anything:

```dotenv
ACCORD_FAILURE_MODE=log
```

Review the logged violations, fix the gaps in your spec (or your API), then switch to `exception` once you're confident the spec reflects reality:

```dotenv
ACCORD_FAILURE_MODE=exception
```

See [Failure modes](#failure-modes) for the full list of options.

### Step 5 — Lock it in with drift detection

Add [fissible/drift](https://github.com/fissible/drift) so that future route changes are caught before they reach production:

```bash
composer require --dev fissible/drift
php artisan accord:validate   # check for drift locally
```

Then add `accord:validate` to your CI pipeline — see [CI / CD](#ci--cd) below.

---

## CI / CD

`accord:validate` exits with a non-zero status code when drift is detected, making it a natural CI gate. Add it alongside your test suite to catch undocumented route changes before they merge.

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

`accord:validate` reports every route that has been added to the app but not yet documented in the spec, or removed from the app but still present in the spec. Either condition fails the build.

`drift:coverage` is an optional second check — it verifies that every registered route has a controller implementation (not just a closure), catching skeleton routes that were never wired up.

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

### Pinning to a specific version

If your repository contains multiple API versions (v1, v2…), you can validate each independently:

```bash
php artisan accord:validate --api-version=v1
php artisan accord:validate --api-version=v2
```

Running without `--api-version` validates all detected versions in one pass.

---

## Why API contracts matter

Every API makes a promise to the apps, services, and teams that depend on it: *send me this shape of data, and I'll return that shape of data.* That promise is the contract. When it breaks — a field goes missing, a type changes, a response shifts structure — the clients depending on your API fail, often in ways that are hard to trace and expensive to fix.

**accord** holds your API to its promises automatically. You describe the contract once in an OpenAPI spec file (a standard, human-readable document describing what your API accepts and returns). Accord then validates every request and response against that spec in real time — catching violations the moment they occur, whether in development before code ships or in production before downstream clients are impacted.

The earlier a breach is caught, the cheaper it is to fix. Accord makes catching it free.

---

## Requirements

- PHP ^8.2
- OpenAPI 3.0.x spec files (YAML or JSON)

## Installation

```bash
composer require fissible/accord
```

### Laravel auto-discovery

The service provider registers automatically. Publish the config to customise it:

```bash
php artisan vendor:publish --tag=accord-config
```

---

## How it works

Accord extracts the API version from the request URI (`/v1/` → `v1`), loads the corresponding spec file (`resources/openapi/v1.yaml`), and validates request bodies and response bodies against the schemas defined in that spec.

Requests and responses with no matching operation, or whose operation defines no schema for the content type, pass silently. Accord only enforces what the spec describes — making it safe to adopt incrementally on existing APIs.

---

## Spec files

Place your OpenAPI 3.0 specs at:

```
resources/openapi/v1.yaml   ← preferred (hand-authored)
resources/openapi/v2.yaml
```

JSON is also supported. When no extension is given in the path pattern, Accord tries `.yaml`, `.yml`, and `.json` in that order. Specs are loaded once per version per process and cached in memory.

---

## Laravel

### Middleware

Register the middleware in your route file or kernel:

```php
// routes/api.php
Route::middleware(\Fissible\Accord\Drivers\Laravel\Http\Middleware\ValidateApiContract::class)
    ->group(function () {
        Route::get('/v1/users', [UserController::class, 'index']);
    });
```

Or globally in `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', ValidateApiContract::class);
})
```

### Configuration

`config/accord.php`:

```php
return [
    'failure_mode'   => env('ACCORD_FAILURE_MODE', 'exception'), // exception | log | callable
    'failure_callable' => null,
    'version_pattern'  => '/^\/v(\d+)(?:\/|$)/',
    'spec_source'    => env('ACCORD_SPEC_SOURCE', 'file'),       // file | url
    'spec_pattern'   => env('ACCORD_SPEC_PATTERN', '{base}/resources/openapi/{version}'),
    'spec_cache_ttl' => env('ACCORD_SPEC_CACHE_TTL', 3600),
];
```

### Loading specs from a URL

Set `spec_source` to `url` and provide a URL pattern with a `{version}` token:

```dotenv
ACCORD_SPEC_SOURCE=url
ACCORD_SPEC_PATTERN=https://api.example.com/openapi/{version}.yaml
```

This is useful when specs are managed externally (e.g. via fissible/studio) or when multiple services validate against a shared central spec. Fetched specs are cached in memory per process; configure a PSR-16 cache in the service provider for persistence across restarts in serverless environments.

### Testing

Add the `AssertsApiContracts` trait to your test case and call `assertResponseMatchesContract` after any API call:

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
        $this->assertResponseMatchesContract($response);
    }
}
```

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

---

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

## Failure modes

| Mode        | Behaviour |
|-------------|-----------|
| `exception` | Throws `ContractViolationException` (default) |
| `log`       | Logs a `warning` via PSR-3; request continues |
| `callable`  | Calls your callable with the `ValidationResult`; request continues |

### Callable example

```php
// config/accord.php
'failure_mode'     => 'callable',
'failure_callable' => function (\Fissible\Accord\ValidationResult $result): void {
    // report to your error tracker, queue a job, send an alert, etc.
    \Sentry\captureMessage(implode(', ', $result->errors));
},
```

---

## Spec sources

### FileSpecSource (default)

Loads specs from the local filesystem. The pattern omits the extension — Accord tries `.yaml`, `.yml`, and `.json` in that order:

```php
use Fissible\Accord\FileSpecSource;

$source = new FileSpecSource('/var/www/app', '{base}/resources/openapi/{version}');
```

### UrlSpecSource

Fetches specs from a remote URL. Ideal for APIs whose specs are managed externally:

```php
use Fissible\Accord\UrlSpecSource;

$source = new UrlSpecSource(
    pattern: 'https://specs.example.com/openapi/{version}.yaml',
    cache:   $psrCache,   // optional PSR-16 — recommended for serverless
    ttl:     3600,
);
```

### Custom sources

Implement `SpecSourceInterface` to load specs from anywhere — a database, a registry, fissible/studio's API:

```php
use Fissible\Accord\SpecSourceInterface;
use cebe\openapi\spec\OpenApi;

class StudioSpecSource implements SpecSourceInterface
{
    public function load(string $version): ?OpenApi { ... }
    public function exists(string $version): bool   { ... }
}
```

---

## Custom framework drivers

Implement `DriverInterface` to integrate Accord with any framework not covered by the bundled drivers:

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

## Version extraction

By default, the version is extracted from the URI path:

| URI | Extracted version | Spec file |
|-----|-------------------|-----------|
| `/v1/users` | `v1` | `resources/openapi/v1.yaml` |
| `/v2/orders/99` | `v2` | `resources/openapi/v2.yaml` |
| `/users` | _(none — passes unconstrained)_ | — |

The pattern is configurable via `version_pattern`. Capture group 1 must match the version number.

---

## License

MIT
