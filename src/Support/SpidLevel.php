<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use InvalidArgumentException;

/**
 * SPID assurance levels: SpidL1, SpidL2, SpidL3, also in their URI form
 * https://www.spid.gov.it/SpidLn (case-insensitive). A higher level
 * satisfies a lower requirement.
 */
final class SpidLevel
{
    private const string URI_PREFIX = 'https://www.spid.gov.it/';

    /**
     * @return int<1, 3>|null the level, or null when the value is not a SPID level
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('#^(?:(?:https://www\.spid\.gov\.it/)?spidl([1-3])|([1-3]))$#i', trim($value), $match) !== 1) {
            return null;
        }

        /** @var int<1, 3> */
        return (int) ($match[1] !== '' ? $match[1] : $match[2]);
    }

    /**
     * @return int<1, 3>|null null when no level is required
     *
     * @throws InvalidArgumentException for a value that is not a SPID level
     */
    public static function required(mixed $configured): ?int
    {
        if ($configured === null || $configured === '') {
            return null;
        }

        return self::parse($configured)
            ?? throw new InvalidArgumentException('spid-laravel-trentino.required_acr must be SpidL1, SpidL2 or SpidL3 (or their https://www.spid.gov.it/ URI), or null.');
    }

    public static function name(int $level): string
    {
        return 'SpidL'.$level;
    }

    public static function uri(int $level): string
    {
        return self::URI_PREFIX.self::name($level);
    }
}
