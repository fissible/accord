# fissible/accord

OpenAPI contract validation for PHP. PSR-7/15 core with first-party drivers for Laravel, Slim, and Mezzio.

<!-- Part of the [Fissible](https://github.com/fissible) suite: **accord** → drift → forge. -->

---

## Requirements

- PHP ^8.2
- OpenAPI 3.0.x spec files (JSON)

## Installation

```bash
composer require fissible/accord
```

### Laravel auto-discovery

The service provider registers automatically. Publish the config if you want to customise it:

```bash
php artisan vendor:publish --tag=accord-config
```

---

## How it works

Accord reads your OpenAPI spec files from disk, matches the incoming request URI to a versioned spec (`/v1/` → `resources/contract/v1.json`), and validates request bodies and response bodies against the schemas defined in that spec.

Requests and responses that have no matching operation, or whose operation defines no schema for the relevant content type, pass silently. Accord only enforces what the spec describes.

---

## Spec files

Place your OpenAPI 3.0 JSON specs at:

```
resources/contract/v1.json
resources/contract/v2.json
```

The path pattern is configurable. Specs are loaded once per version per process and cached in memory.

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
    'failure_mode'     => env('ACCORD_FAILURE_MODE', 'exception'), // exception | log | callable
    'failure_callable' => null,
    'version_pattern'  => '/^\/v(\d+)(?:\/|$)/',
    'spec_pattern'     => '{base}/resources/contract/{version}.json',
];
```

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
    'spec_pattern' => '{base}/openapi/{version}.json',
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
    // report to your error tracker, queue a job, etc.
    \Sentry\captureMessage(implode(', ', $result->errors));
},
```

---

## Custom drivers

Implement `DriverInterface` to integrate Accord with any framework:

```php
use Fissible\Accord\DriverInterface;
use Fissible\Accord\FailureMode;

class MyFrameworkDriver implements DriverInterface
{
    public function resolveSpecPath(string $version): string
    {
        return sprintf('/path/to/specs/%s.json', $version);
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

Then build the validator manually:

```php
use Fissible\Accord\AccordFactory;
use Fissible\Accord\AccordMiddleware;

$middleware = AccordFactory::make([
    'failure_mode' => 'exception',
    'spec_pattern' => '{base}/specs/{version}.json',
], $basePath);
```

---

## Version extraction

By default, the version is extracted from the URI path:

| URI | Extracted version | Spec file |
|-----|-------------------|-----------|
| `/v1/users` | `v1` | `resources/contract/v1.json` |
| `/v2/orders/99` | `v2` | `resources/contract/v2.json` |
| `/users` | _(none — passes unconstrained)_ | — |

The pattern is configurable via `version_pattern`. Capture group 1 must match the version number.

---

## License

MIT
