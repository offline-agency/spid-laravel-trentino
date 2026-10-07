<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Illuminate\Support\Facades\Config;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

/**
 * Pseudonymizes identifiers for logs: a keyed hash lets support correlate log
 * lines for the same person without storing the fiscal code. Fiscal codes have
 * low entropy, so a plain unsalted hash would be reversible.
 */
final class LogRedactor
{
    public static function hash(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = Config::get('app.key');

        return substr(hash_hmac('sha256', $value, is_string($key) ? $key : ''), 0, 16);
    }

    /**
     * @return array{sub: string, fiscal_code: string}
     */
    public static function user(SpidTrentinoUser $user): array
    {
        return [
            'sub' => self::hash($user->getSub()),
            'fiscal_code' => self::hash($user->getFiscalNumber()),
        ];
    }
}
