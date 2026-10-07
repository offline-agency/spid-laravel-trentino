<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * SPID identity returned by AAC Trentino's userinfo endpoint.
 *
 * Hydration is tolerant: claims of an unexpected type become '' (scalars) or
 * [] (nested enti-* claims) instead of failing.
 *
 * @implements Arrayable<string, mixed>
 */
class SpidTrentinoUser implements Arrayable, JsonSerializable
{
    private string $sub = '';

    private string $zoneinfo = '';

    private string $preferredUsername = '';

    private string $locale = '';

    private string $givenName = '';

    private string $realm = '';

    private string $id = '';

    private string $familyName = '';

    private string $email = '';

    /** @var array<array-key, mixed> */
    private array $entiIssuerSource = [];

    /** @var array<array-key, mixed> */
    private array $entiAcr = [];

    /** @var array<array-key, mixed> */
    private array $entiSpid = [];

    /** @var array<array-key, mixed> */
    private array $entiCodiceFiscale = [];

    /**
     * @param  array<array-key, mixed>|stdClass  $data  userinfo payload
     */
    public function __construct(array|stdClass $data = [])
    {
        $data = self::toArrayRecursive($data);

        $this->setSub(self::stringClaim($data, 'sub'))
            ->setZoneinfo(self::stringClaim($data, 'zoneinfo'))
            ->setPreferredUsername(self::stringClaim($data, 'preferred_username'))
            ->setLocale(self::stringClaim($data, 'locale'))
            ->setGivenName(self::stringClaim($data, 'given_name'))
            ->setRealm(self::stringClaim($data, 'realm'))
            ->setId(self::stringClaim($data, 'id'))
            ->setFamilyName(self::stringClaim($data, 'family_name'))
            ->setEmail(self::stringClaim($data, 'email'))
            ->setEntiIssuerSource(self::arrayClaim($data, 'enti-issuersource'))
            ->setEntiAcr(self::arrayClaim($data, 'enti-acr'))
            ->setEntiSpid(self::arrayClaim($data, 'enti-spid'))
            ->setEntiCodiceFiscale(self::arrayClaim($data, 'enti-codicefiscale'));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @throws InvalidArgumentException when $json is not a JSON object and $throwOnError is true
     */
    public static function fromJson(string $json, bool $throwOnError = true): self
    {
        $data = json_decode($json, true);

        if (is_array($data)) {
            return new self($data);
        }

        if ($throwOnError) {
            throw new InvalidArgumentException('Invalid SPID user JSON: '.(json_last_error() === JSON_ERROR_NONE ? 'not an object' : json_last_error_msg()));
        }

        return new self;
    }

    public static function fromStdClass(stdClass $object): self
    {
        return new self($object);
    }

    public function getSub(): string
    {
        return $this->sub;
    }

    public function setSub(string $sub): self
    {
        $this->sub = $sub;

        return $this;
    }

    public function getZoneinfo(): string
    {
        return $this->zoneinfo;
    }

    public function setZoneinfo(string $zoneinfo): self
    {
        $this->zoneinfo = $zoneinfo;

        return $this;
    }

    public function getPreferredUsername(): string
    {
        return $this->preferredUsername;
    }

    public function setPreferredUsername(string $preferredUsername): self
    {
        $this->preferredUsername = $preferredUsername;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getGivenName(): string
    {
        return $this->givenName;
    }

    public function setGivenName(string $givenName): self
    {
        $this->givenName = $givenName;

        return $this;
    }

    public function getRealm(): string
    {
        return $this->realm;
    }

    public function setRealm(string $realm): self
    {
        $this->realm = $realm;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFamilyName(): string
    {
        return $this->familyName;
    }

    public function setFamilyName(string $familyName): self
    {
        $this->familyName = $familyName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiIssuerSource(): array
    {
        return $this->entiIssuerSource;
    }

    /** @param array<array-key, mixed>|stdClass $entiIssuerSource */
    public function setEntiIssuerSource(array|stdClass $entiIssuerSource): self
    {
        $this->entiIssuerSource = self::toArrayRecursive($entiIssuerSource);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiAcr(): array
    {
        return $this->entiAcr;
    }

    /** @param array<array-key, mixed>|stdClass $entiAcr */
    public function setEntiAcr(array|stdClass $entiAcr): self
    {
        $this->entiAcr = self::toArrayRecursive($entiAcr);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiSpid(): array
    {
        return $this->entiSpid;
    }

    /** @param array<array-key, mixed>|stdClass $entiSpid */
    public function setEntiSpid(array|stdClass $entiSpid): self
    {
        $this->entiSpid = self::toArrayRecursive($entiSpid);

        return $this;
    }

    /** @return array<array-key, mixed> */
    public function getEntiCodiceFiscale(): array
    {
        return $this->entiCodiceFiscale;
    }

    /** @param array<array-key, mixed>|stdClass $entiCodiceFiscale */
    public function setEntiCodiceFiscale(array|stdClass $entiCodiceFiscale): self
    {
        $this->entiCodiceFiscale = self::toArrayRecursive($entiCodiceFiscale);

        return $this;
    }

    /**
     * Fiscal number from enti-codicefiscale.fiscalCode (for example TINIT-RSSMRA80A01H501U).
     */
    public function getFiscalNumber(): string
    {
        return trim(self::stringClaim($this->entiCodiceFiscale, 'fiscalCode'));
    }

    public function getName(): string
    {
        return $this->givenName;
    }

    public function getSurname(): string
    {
        return $this->familyName;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'zoneinfo' => $this->zoneinfo,
            'enti-issuersource' => $this->entiIssuerSource,
            'preferred_username' => $this->preferredUsername,
            'locale' => $this->locale,
            'given_name' => $this->givenName,
            'email' => $this->email,
            'enti-acr' => $this->entiAcr,
            'enti-spid' => $this->entiSpid,
            'realm' => $this->realm,
            'enti-codicefiscale' => $this->entiCodiceFiscale,
            'id' => $this->id,
            'family_name' => $this->familyName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<array-key, mixed>|stdClass  $value
     * @return array<array-key, mixed>
     */
    private static function toArrayRecursive(array|stdClass $value): array
    {
        $decoded = json_decode((string) json_encode($value), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function stringClaim(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arrayClaim(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
