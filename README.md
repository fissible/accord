# fissible/accord

OpenAPI contract validation for PHP. PSR-7/15 core with first-party drivers for Laravel, Slim, and Mezzio.

**Start here.** accord is the foundation of the Fissible suite — the other packages build on top of it.

---

## The Fissible suite

Fissible is a set of three focused PHP packages for keeping your API and its documentation honest with each other. They form a continuous loop — not a one-time setup, but an ongoing development cycle.

```
      ┌─────────────────────────────────────────┐
      │                                         ▼
  [forge]  ──────────────────────────────►  [accord]
  generate / update spec                   validate at runtime
      ▲                                         │
      │                                         ▼
      └──────────────────────────────────  [drift]
                                           detect drift, bump version
```

Your API grows. drift detects that routes have moved. You update or regenerate the spec with forge. accord picks up the new spec and enforces it. drift watches for the next change. Repeat.

### [fissible/forge](https://github.com/fissible/forge)

Scaffolds an OpenAPI spec from your existing routes, inferring request body schemas from your FormRequest validation rules. Use it to get started with a spec, and again whenever a new API version needs documenting.

### fissible/accord ← you are here

The runtime enforcer. Validates every request and response against the spec in real time. Lives in your application permanently — the spec it validates against evolves, but accord itself stays put.

### [fissible/drift](https://github.com/fissible/drift)

Detects when the routes your application actually serves have drifted from what the spec describes. Recommends a semver bump, generates a changelog entry, and closes the loop — signalling that it's time to update the spec.

---

## Recommended setup (Laravel)

accord is the only package you need to install for runtime validation. forge and drift are optional companions — install them separately as your needs grow.

```bash
composer require fissible/accord
```

**Don't have a spec yet?** Install [fissible/forge](https://github.com/fissible/forge) separately (dev or global) to scaffold one from your existing routes, then come back here:

```bash
composer require --dev fissible/forge
php artisan accord:generate --title="My API"
# fill in response schemas in resources/openapi/v1.yaml
```

**Once you have a spec**, register the accord middleware and you're done:

```php
// routes/api.php or bootstrap/app.php
ValidateApiContract::class
```

**As your API evolves**, add [fissible/drift](https://github.com/fissible/drift) to catch undocumented changes in CI:

```bash
composer require --dev fissible/drift
php artisan accord:validate
```

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
