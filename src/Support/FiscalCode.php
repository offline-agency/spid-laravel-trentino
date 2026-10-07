<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

/**
 * Canonical form of an Italian fiscal code, used to match and store local
 * users: trimmed, uppercase, without the TINIT- prefix that SPID uses for
 * the fiscalNumber attribute.
 */
final class FiscalCode
{
    private const string SPID_PREFIX = 'TINIT-';

    public static function normalize(string $value): string
    {
        $code = strtoupper(trim($value));

        if (str_starts_with($code, self::SPID_PREFIX)) {
            $code = trim(substr($code, strlen(self::SPID_PREFIX)));
        }

        return $code;
    }
}
