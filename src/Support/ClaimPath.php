<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

/**
 * Reads a claim through configurable paths of the form "source:dot.path",
 * where source is id_token (the verified ID token claims) or userinfo (the
 * userinfo response), for example "userinfo:enti-acr.acr".
 */
final class ClaimPath
{
    /**
     * First non-empty scalar value found along the paths, in order.
     *
     * @param  mixed  $paths  a list of paths or a single path
     * @param  array<string, array<array-key, mixed>>  $sources  claims by source name
     */
    public static function first(mixed $paths, array $sources): ?string
    {
        foreach (is_array($paths) ? $paths : [$paths] as $path) {
            $value = self::read($path, $sources);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $sources
     */
    private static function read(mixed $path, array $sources): ?string
    {
        if (! is_string($path) || preg_match('/^([a-z_]+):(.+)$/', $path, $match) !== 1 || ! isset($sources[$match[1]])) {
            return null;
        }

        $value = $sources[$match[1]];

        foreach (explode('.', $match[2]) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return (is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '' ? (string) $value : null;
    }
}
