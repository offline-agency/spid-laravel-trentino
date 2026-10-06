<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Support\Facades\Session;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;

/**
 * Single reader and writer of the access-token expiry kept in the session.
 * Accepts the formats 1.x stored (Carbon instances, strings, timestamps).
 */
final class SessionExpiry
{
    public static function put(?DateTimeInterface $expiresAt): void
    {
        if ($expiresAt === null) {
            Session::forget(SessionKeys::ACCESS_TOKEN_EXPIRES_AT);

            return;
        }

        Session::put(SessionKeys::ACCESS_TOKEN_EXPIRES_AT, CarbonImmutable::instance($expiresAt)->toIso8601String());
    }

    public static function get(): ?CarbonImmutable
    {
        $raw = Session::get(SessionKeys::ACCESS_TOKEN_EXPIRES_AT);

        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        if (is_int($raw)) {
            return CarbonImmutable::createFromTimestamp($raw);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * True when a stored expiry has passed or cannot be parsed.
     */
    public static function hasExpired(): bool
    {
        if (! Session::has(SessionKeys::ACCESS_TOKEN_EXPIRES_AT)) {
            return false;
        }

        $expiresAt = self::get();

        return $expiresAt === null || $expiresAt->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public static function expiresWithin(int $seconds): bool
    {
        $expiresAt = self::get();

        return $expiresAt !== null && $expiresAt->subSeconds($seconds)->lessThanOrEqualTo(CarbonImmutable::now());
    }
}
