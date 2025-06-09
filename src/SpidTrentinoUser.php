<?php

namespace OfflineAgency\SpidLaravelTrentino;

class SpidTrentinoUser implements \JsonSerializable
{
  protected array $attributes;

  public function __construct(array $attributes)
  {
    $this->attributes = $attributes;
  }

  public function __get(string $key): mixed
  {
    return match ($key) {
      'fiscalNumber' => $this->stripPrefix($this->attributes['codicefiscale']['fiscalCode'] ?? null),
      'email' => $this->attributes['email'] ?? null,
      'givenName' => $this->attributes['given_name'] ?? null,
      'familyName' => $this->attributes['family_name'] ?? null,
      'birthdate' => $this->attributes['birthdate'] ?? null,
      'gender' => $this->attributes['gender'] ?? null,
      'subject', //TODO: need to be verified
      'openId' => $this->attributes['sub'] ?? null,
      default => $this->attributes[$key] ?? null,
    };
  }

  protected function stripPrefix(?string $value): ?string
  {
    return str_starts_with($value, 'TINIT-') ? substr($value, 6) : $value;
  }

  public function getName(): ?string
  {
    return trim("{$this->givenName} {$this->familyName}");
  }

  public function toArray(): array
  {
    return [
      'openId'       => $this->openId,
      'subject'      => $this->subject,
      'fiscalNumber' => $this->fiscalNumber,
      'email'        => $this->email,
      'givenName'    => $this->givenName,
      'familyName'   => $this->familyName,
      'birthdate'    => $this->birthdate,
      'gender'       => $this->gender,
    ];
  }

  public function jsonSerialize(): array
  {
    return $this->toArray();
  }
}
