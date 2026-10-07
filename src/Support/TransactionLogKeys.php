<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Versioned HMAC keys of the transaction log.
 *
 * Keys come from `transaction_log.keys`, either an array `id => secret` or the
 * environment string `id1:secret1,id2:secret2` (a secret may be `base64:...`).
 * The id `app` always means APP_KEY, used exactly as 2.x used it, so rows
 * written before versioned keys existed keep verifying.
 */
final class TransactionLogKeys
{
    public const string APP = 'app';

    private const string ID_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    public static function currentId(): string
    {
        $id = Config::get('spid-laravel-trentino.transaction_log.current_key');

        return is_string($id) && $id !== '' ? $id : self::APP;
    }

    /**
     * @return array{0: string, 1: string} key id and secret used for new rows
     *
     * @throws LogicException when the current key has no secret
     */
    public static function current(): array
    {
        $id = self::currentId();
        $secret = self::secret($id);

        if ($secret === null) {
            throw new LogicException("The transaction log key [{$id}] is not configured.");
        }

        return [$id, $secret];
    }

    /**
     * Secret of a key id; `null` means `app` (rows written before key ids existed).
     */
    public static function secret(?string $id): ?string
    {
        if ($id === null || $id === self::APP) {
            $appKey = Config::get('app.key');

            return is_string($appKey) && $appKey !== '' ? $appKey : null;
        }

        return self::configured()[$id] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function configured(): array
    {
        $raw = Config::get('spid-laravel-trentino.transaction_log.keys');
        $pairs = [];

        if (is_string($raw)) {
            foreach (explode(',', $raw) as $pair) {
                [$id, $secret] = array_pad(explode(':', trim($pair), 2), 2, '');
                $pairs[$id] = $secret;
            }
        } elseif (is_array($raw)) {
            $pairs = $raw;
        }

        $keys = [];

        foreach ($pairs as $id => $secret) {
            $secret = is_string($secret) ? self::decode($secret) : null;

            if (is_string($id) && $id !== self::APP && preg_match(self::ID_PATTERN, $id) === 1 && $secret !== null) {
                $keys[$id] = $secret;
            }
        }

        return $keys;
    }

    private static function decode(string $secret): ?string
    {
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $secret === '' ? null : $secret;
    }
}
