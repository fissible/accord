<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Failure Mode
    |--------------------------------------------------------------------------
    | How contract violations are reported. Options: exception | log | callable
    |
    */
    'failure_mode' => env('ACCORD_FAILURE_MODE', 'exception'),

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
];
