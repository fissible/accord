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
    | Spec Pattern
    |--------------------------------------------------------------------------
    | Path pattern for locating spec files. Tokens: {base}, {version}.
    |
    */
    'spec_pattern' => '{base}/resources/contract/{version}.json',
];
