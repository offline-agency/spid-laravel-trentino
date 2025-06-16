<?php
declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use JsonSerializable;

class SpidTrentinoUser implements JsonSerializable
{
  /** Payload grezzo restituito da AAC */
  protected array $attributes;

  public function __construct(array $attributes = [])
  {
    $this->attributes = $attributes;
  }

  /* =========================================================
     Accesso dinamico di comodo (compatibilità retro-compat.) */
  public function __get(string $key): mixed
  {
    return match ($key) {
      /* identificativi principali */
      'sub', 'openId'         => $this->attributes['sub'] ?? $this->attributes['openId'] ?? null,

      /* anagrafica */
      'preferredUsername'     => $this->attributes['preferred_username'] ?? null,
      'locale'                => $this->attributes['locale'] ?? null,
      'zoneInfo'              => $this->attributes['zoneinfo'] ?? null,
      'givenName'             => $this->attributes['given_name'] ?? null,
      'familyName'            => $this->attributes['family_name'] ?? null,
      'birthdate'             => $this->attributes['birthdate'] ?? null,
      'gender'                => $this->attributes['gender'] ?? null,
      'email'                 => $this->attributes['email'] ?? null,

      /* dominio / attributi SPID Trentino */
      'realm'                 => $this->attributes['realm'] ?? null,

      /* issuer source */
      'issuerSource'          => $this->nested('enti-issuersource', 'issuerSource'),
      'issuerSourceId'        => $this->nested('enti-issuersource', 'id'),

      /* acr */
      'acr'                   => $this->nested('enti-acr', 'acr'),
      'acrId'                 => $this->nested('enti-acr', 'id'),

      /* spid */
      'isSpid'                => $this->nested('enti-spid', 'isSpid'),
      'spidCode'              => $this->nested('enti-spid', 'spidCode'),
      'spidId'                => $this->nested('enti-spid', 'id'),

      /* codice fiscale */
      'fiscalNumber'          => $this->stripPrefix(
        $this->nested('enti-codicefiscale', 'fiscalCode')
      ),
      'codiceFiscaleId'       => $this->nested('enti-codicefiscale', 'id'),

      default                 => $this->attributes[$key] ?? null,
    };
  }

  /* =========================================================
     Helper per navigare i sotto-array stdClass */
  protected function nested(string $root, string $leaf): mixed
  {
    return $this->attributes[$root][$leaf]
      ?? $this->attributes[$root]['stdClass'][$leaf]
      ?? null;
  }

  protected function stripPrefix(?string $value): ?string
  {
    return $value && str_starts_with($value, 'TINIT-') ? substr($value, 6) : $value;
  }

  protected function prefixFiscal(?string $value): ?string
  {
    if ($value === null) {
      return null;
    }
    return str_starts_with($value, 'TINIT-') ? $value : 'TINIT-' . $value;
  }

  protected function setNested(string $root, string $leaf, mixed $value): void
  {
    if (!isset($this->attributes[$root]) || !is_array($this->attributes[$root])) {
      $this->attributes[$root] = [];
    }
    $this->attributes[$root][$leaf] = $value;
  }

  /* =========================================================
     Getter & Setter generati automaticamente               */

  // sub / openId
  public function getSub(): ?string         { return $this->sub; }
  public function setSub(?string $v): self  { $this->attributes['sub'] = $v; return $this; }

  public function getOpenId(): ?string         { return $this->openId; }
  public function setOpenId(?string $v): self  { $this->attributes['openId'] = $v; return $this; }

  // preferredUsername
  public function getPreferredUsername(): ?string        { return $this->preferredUsername; }
  public function setPreferredUsername(?string $v): self { $this->attributes['preferred_username'] = $v; return $this; }

  // locale
  public function getLocale(): ?string        { return $this->locale; }
  public function setLocale(?string $v): self { $this->attributes['locale'] = $v; return $this; }

  // zoneInfo
  public function getZoneInfo(): ?string        { return $this->zoneInfo; }
  public function setZoneInfo(?string $v): self { $this->attributes['zoneinfo'] = $v; return $this; }

  // givenName
  public function getGivenName(): ?string        { return $this->givenName; }
  public function setGivenName(?string $v): self { $this->attributes['given_name'] = $v; return $this; }

  // familyName
  public function getFamilyName(): ?string        { return $this->familyName; }
  public function setFamilyName(?string $v): self { $this->attributes['family_name'] = $v; return $this; }

  // birthdate
  public function getBirthdate(): ?string        { return $this->birthdate; }
  public function setBirthdate(?string $v): self { $this->attributes['birthdate'] = $v; return $this; }

  // gender
  public function getGender(): ?string        { return $this->gender; }
  public function setGender(?string $v): self { $this->attributes['gender'] = $v; return $this; }

  // email
  public function getEmail(): ?string        { return $this->email; }
  public function setEmail(?string $v): self { $this->attributes['email'] = $v; return $this; }

  // realm
  public function getRealm(): ?string        { return $this->realm; }
  public function setRealm(?string $v): self { $this->attributes['realm'] = $v; return $this; }

  // issuerSource
  public function getIssuerSource(): ?string        { return $this->issuerSource; }
  public function setIssuerSource(?string $v): self { $this->setNested('enti-issuersource', 'issuerSource', $v); return $this; }

  public function getIssuerSourceId(): ?string        { return $this->issuerSourceId; }
  public function setIssuerSourceId(?string $v): self { $this->setNested('enti-issuersource', 'id', $v); return $this; }

  // acr
  public function getAcr(): ?string        { return $this->acr; }
  public function setAcr(?string $v): self { $this->setNested('enti-acr', 'acr', $v); return $this; }

  public function getAcrId(): ?string        { return $this->acrId; }
  public function setAcrId(?string $v): self { $this->setNested('enti-acr', 'id', $v); return $this; }

  // isSpid
  public function getIsSpid(): ?bool        { return $this->isSpid; }
  public function setIsSpid(?bool $v): self { $this->setNested('enti-spid', 'isSpid', $v); return $this; }

  // spidCode
  public function getSpidCode(): ?string        { return $this->spidCode; }
  public function setSpidCode(?string $v): self { $this->setNested('enti-spid', 'spidCode', $v); return $this; }

  // spidId
  public function getSpidId(): ?string        { return $this->spidId; }
  public function setSpidId(?string $v): self { $this->setNested('enti-spid', 'id', $v); return $this; }

  // fiscalNumber
  public function getFiscalNumber(): ?string        { return $this->fiscalNumber; }
  public function setFiscalNumber(?string $v): self { $this->setNested('enti-codicefiscale', 'fiscalCode', $this->prefixFiscal($v)); return $this; }

  // codiceFiscaleId
  public function getCodiceFiscaleId(): ?string        { return $this->codiceFiscaleId; }
  public function setCodiceFiscaleId(?string $v): self { $this->setNested('enti-codicefiscale', 'id', $v); return $this; }

  /* =========================================================
     Helper derivati                                          */
  public function getName(): ?string
  {
    return trim("{$this->givenName} {$this->familyName}");
  }

  /** Alias che impedisce l’eccezione “undefined method getSurname()” */
  public function getSurname(): ?string
  {
    return $this->familyName;
  }

  public function toArray(): array
  {
    return [
      'sub'               => $this->sub,
      'openId'            => $this->openId,
      'preferredUsername' => $this->preferredUsername,
      'locale'            => $this->locale,
      'zoneInfo'          => $this->zoneInfo,
      'givenName'         => $this->givenName,
      'familyName'        => $this->familyName,
      'birthdate'         => $this->birthdate,
      'gender'            => $this->gender,
      'email'             => $this->email,
      'realm'             => $this->realm,
      'issuerSource'      => $this->issuerSource,
      'issuerSourceId'    => $this->issuerSourceId,
      'acr'               => $this->acr,
      'acrId'             => $this->acrId,
      'isSpid'            => $this->isSpid,
      'spidCode'          => $this->spidCode,
      'spidId'            => $this->spidId,
      'fiscalNumber'      => $this->fiscalNumber,
      'codiceFiscaleId'   => $this->codiceFiscaleId,
    ];
  }

  public function jsonSerialize(): array
  {
    return $this->toArray();
  }
}
