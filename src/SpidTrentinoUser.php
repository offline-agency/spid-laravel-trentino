<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use stdClass;

/**
 * Data‑transfer object that mirrors the SPID Trentino identity response
 * and exposes strongly‑typed getters / setters plus Laravel‑style toArray().
 *
 * You can instantiate the DTO directly with the payload:
 * ```php
 * $user = new SpidTrentinoUser($payload);     // $payload may be an array OR an stdClass
 * $user = SpidTrentinoUser::fromJson($json);  // convenience helpers also provided
 * ```
 */
class SpidTrentinoUser implements Arrayable, JsonSerializable
{
    // ───────────────────────────────────────────────────────────────
    // Core scalar claims (pre‑initialized to avoid uninitialized‑typed‑property errors)
    // ───────────────────────────────────────────────────────────────
    private string $sub = '';

    private string $zoneinfo = '';

    private string $preferredUsername = '';

    private string $locale = '';

    private string $givenName = '';

    private string $realm = '';

    private string $id = '';

    private string $familyName = '';

    // ───────────────────────────────────────────────────────────────
    // Complex / nested claims (kept as associative arrays for brevity)
    // ───────────────────────────────────────────────────────────────
    private array $entiIssuerSource = [];

    private array $entiAcr = [];

    private array $entiSpid = [];

    private array $entiCodiceFiscale = [];

    // ───────────────────────────────────────────────────────────────
    // Constructor – optional one‑shot hydration
    // ───────────────────────────────────────────────────────────────

    /**
     * @param  array|stdClass  $data  SPID payload (associative array or stdClass)
     */
    public function __construct(array|stdClass $data = [])
    {
        if ($data !== []) {
            // Ensure we have an array for uniform processing
            if ($data instanceof stdClass) {
                $data = json_decode(json_encode($data), true);
            }

            // Scalar claims
            $this->setSub($data['sub'] ?? '')
                ->setZoneinfo($data['zoneinfo'] ?? '')
                ->setPreferredUsername($data['preferred_username'] ?? '')
                ->setLocale($data['locale'] ?? '')
                ->setGivenName($data['given_name'] ?? '')
                ->setRealm($data['realm'] ?? '')
                ->setId($data['id'] ?? '')
                ->setFamilyName($data['family_name'] ?? '')

                // Nested claims
                ->setEntiIssuerSource($data['enti-issuersource'] ?? [])
                ->setEntiAcr($data['enti-acr'] ?? [])
                ->setEntiSpid($data['enti-spid'] ?? [])
                ->setEntiCodiceFiscale($data['enti-codicefiscale'] ?? []);
        }
    }

    // ───────────────────────────────────────────────────────────────
    // Statics - hydrators / factories (all delegate to the constructor)
    // ───────────────────────────────────────────────────────────────

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json, bool $throwOnError = true): self
    {
        $array = json_decode($json, true);

        if ($array === null && json_last_error() !== JSON_ERROR_NONE) {
            if ($throwOnError) {
                throw new \InvalidArgumentException('Invalid JSON supplied: '.json_last_error_msg());
            }
            $array = [];
        }

        return new self($array);
    }

    public static function fromStdClass(stdClass $object): self
    {
        return new self($object);
    }

    // ───────────────────────────────────────────────────────────────
    // Getters & Setters – scalar claims
    // ───────────────────────────────────────────────────────────────

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

    // ───────────────────────────────────────────────────────────────
    // Getters & Setters – nested/complex claims (arrays)
    // ───────────────────────────────────────────────────────────────

    /** @return array{issuerSource?:string,id?:string} */
    public function getEntiIssuerSource(): array
    {
        return $this->entiIssuerSource;
    }

    public function setEntiIssuerSource(array|stdClass $entiIssuerSource): self
    {
        $this->entiIssuerSource = (array) $entiIssuerSource;

        return $this;
    }

    /** @return array{acr?:string,id?:string} */
    public function getEntiAcr(): array
    {
        return $this->entiAcr;
    }

    public function setEntiAcr(array|stdClass $entiAcr): self
    {
        $this->entiAcr = (array) $entiAcr;

        return $this;
    }

    /** @return array{isSpid?:string,spidCode?:string,id?:string} */
    public function getEntiSpid(): array
    {
        return $this->entiSpid;
    }

    public function setEntiSpid(array|stdClass $entiSpid): self
    {
        $this->entiSpid = (array) $entiSpid;

        return $this;
    }

    /** @return array{fiscalCode?:string,id?:string} */
    public function getEntiCodiceFiscale(): array
    {
        return $this->entiCodiceFiscale;
    }

    public function setEntiCodiceFiscale(array|stdClass $entiCodiceFiscale): self
    {
        $this->entiCodiceFiscale = (array) $entiCodiceFiscale;

        return $this;
    }

    // ───────────────────────────────────────────────────────────────
    // Derived helpers
    // ───────────────────────────────────────────────────────────────

    public function getFiscalNumber(): string
    {
        return $this->entiCodiceFiscale['fiscalCode'] ?? '';
    }

    public function getName(): string
    {
        return $this->givenName;
    }

    public function getSurname(): string
    {
        return $this->familyName;
    }

    // ───────────────────────────────────────────────────────────────
    // Arrayable / JsonSerializable contracts
    // ───────────────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'zoneinfo' => $this->zoneinfo,
            'enti-issuersource' => $this->entiIssuerSource,
            'preferred_username' => $this->preferredUsername,
            'locale' => $this->locale,
            'given_name' => $this->givenName,
            'enti-acr' => $this->entiAcr,
            'enti-spid' => $this->entiSpid,
            'realm' => $this->realm,
            'enti-codicefiscale' => $this->entiCodiceFiscale,
            'id' => $this->id,
            'family_name' => $this->familyName,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
