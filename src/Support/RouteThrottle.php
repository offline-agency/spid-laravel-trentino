<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use InvalidArgumentException;

/**
 * Rate limit of the SPID login and callback routes, configured as "max" or
 * "max,decayMinutes" (requests per client IP and route).
 */
final class RouteThrottle
{
    /** Name of the rate limiter registered by the service provider. */
    public const string LIMITER = 'spid-laravel-trentino';

    /**
     * @return array{0: int, 1: int}|null [max requests, decay minutes], or null when disabled
     *
     * @throws InvalidArgumentException for a value that is neither empty nor "max[,decayMinutes]"
     */
    public static function parse(mixed $value): ?array
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        $parts = is_int($value) || is_string($value) ? array_map(trim(...), explode(',', (string) $value)) : [];

        if ($parts === [] || count($parts) > 2) {
            throw self::invalid();
        }

        $parts[1] ??= '1';

        foreach ($parts as $part) {
            if (preg_match('/^[1-9]\d*$/', $part) !== 1) {
                throw self::invalid();
            }
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('spid-laravel-trentino.throttle must be "max" or "max,decayMinutes" with positive integers, or null to disable it.');
    }
}
