<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Support;

use Carbon\CarbonImmutable;
use stdClass;

/**
 * Token endpoint response normalized from what jumbojett returns (stdClass),
 * or from an array.
 */
final readonly class TokenResponse
{
    public function __construct(
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?string $idToken,
        public ?int $expiresIn,
        public ?string $error,
    ) {}

    public static function from(mixed $response): self
    {
        $data = match (true) {
            $response instanceof stdClass => get_object_vars($response),
            is_array($response) => $response,
            default => [],
        };

        return new self(
            self::string($data, 'access_token'),
            self::string($data, 'refresh_token'),
            self::string($data, 'id_token'),
            is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : null,
            self::string($data, 'error'),
        );
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresIn === null ? null : CarbonImmutable::now()->addSeconds($this->expiresIn);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
