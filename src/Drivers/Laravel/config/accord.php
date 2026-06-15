<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Failure Mode
    |--------------------------------------------------------------------------
    | How contract violations are reported. Options: exception | log | callable
    |
    | May be a single value (applied to both directions) or an array with
    | separate 'request' and 'response' modes, e.g.:
    |   'failure_mode' => ['request' => 'exception', 'response' => 'log'],
    | A missing array key falls back (response → request, request → exception).
    |
    */
    'failure_mode' => env('ACCORD_FAILURE_MODE', 'exception'),

    /*
    |--------------------------------------------------------------------------
    | Request Violation Status
    |--------------------------------------------------------------------------
    | HTTP status returned (in the Laravel driver) when a REQUEST violates the
    | contract under exception mode. Must be a 4xx; anything else falls back to
    | 422. Response violations are never rendered as a client error.
    |
    */
    'request_violation_status' => (int) env('ACCORD_REQUEST_VIOLATION_STATUS', 422),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    | Laravel log channel used when failure_mode is "log". Leave null to use
    | the application's default PSR logger.
    |
    */
    'log_channel' => env('ACCORD_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Failure Callable
    |--------------------------------------------------------------------------
    | Invoked when failure_mode is "callable". Must be a callable resolvable
    | via the service container or a [Class::class, 'method'] array.
    |
    */
    'failure_callable' => null,

    /*
    |--------------------------------------------------------------------------
    | Version Pattern
    |--------------------------------------------------------------------------
    | Regex used to extract the API version from the URI path.
    | Capture group 1 must match the version number (e.g. "1" from /v1/).
    |
    */
    'version_pattern' => '/^\/v(\d+)(?:\/|$)/',

    /*
    |--------------------------------------------------------------------------
    | Spec Source
    |--------------------------------------------------------------------------
    | Where to load OpenAPI specs from. Options: 'file' | 'url'
    |
    */
    'spec_source' => env('ACCORD_SPEC_SOURCE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Spec Pattern
    |--------------------------------------------------------------------------
    | For 'file' source: path template using {base} and {version} tokens.
    |   Do not include a file extension — .yaml, .yml, and .json are tried
    |   in that order. Include an extension to pin to a specific format.
    |
    | For 'url' source: URL template using {version} token, e.g.:
    |   https://api.example.com/openapi/{version}.yaml
    |
    */
    'spec_pattern' => env('ACCORD_SPEC_PATTERN', '{base}/resources/openapi/{version}'),

    /*
    |--------------------------------------------------------------------------
    | Spec Cache TTL
    |--------------------------------------------------------------------------
    | Seconds to cache remotely fetched specs (url source only).
    | In standard PHP-FPM the in-process cache is sufficient; this is for
    | serverless or short-lived process environments.
    |
    */
    'spec_cache_ttl' => env('ACCORD_SPEC_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Debug Diagnostics
    |--------------------------------------------------------------------------
    | When true, Accord logs (at "debug" level) every request and response it
    | SKIPPED instead of validated, with the reason — unversioned, missing_spec,
    | unmatched_operation, missing_request_schema, missing_response_schema, or
    | unsupported_media_type. Use it to answer "is my API actually being
    | validated, and if not, why?". Off by default and zero overhead when off;
    | leave it off in production unless you are diagnosing silent non-validation.
    |
    */
    'debug' => (bool) env('ACCORD_DEBUG', false),
];
