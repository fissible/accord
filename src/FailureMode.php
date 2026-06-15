<?php

declare(strict_types=1);

namespace Fissible\Accord;

enum FailureMode: string
{
    case Exception = 'exception';
    case Log       = 'log';
    case Callable  = 'callable';

    /**
     * Resolve a string|array failure-mode config into [request, response] modes.
     *
     * - A scalar string applies to both directions.
     * - An array may set 'request' and/or 'response'; a MISSING key falls back
     *   (response → request, request → Exception default).
     * - null / [] fall back to Exception for both.
     * - A present-but-unknown string (scalar or array value) throws ValueError
     *   via self::from — typos are never silently swallowed.
     *
     * @param string|array<string, string>|null $config
     * @return array{0: FailureMode, 1: FailureMode}
     */
    public static function resolvePair(string|array|null $config): array
    {
        if ($config === null) {
            return [self::Exception, self::Exception];
        }

        if (is_array($config)) {
            $request  = isset($config['request'])  ? self::from($config['request'])  : self::Exception;
            $response = isset($config['response']) ? self::from($config['response']) : $request;

            return [$request, $response];
        }

        $mode = self::from($config);

        return [$mode, $mode];
    }
}
