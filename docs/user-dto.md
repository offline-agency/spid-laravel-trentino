# SPID user DTO

`OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser` holds the identity returned by AAC's userinfo endpoint. It is what `handleCallback()` returns, what the events carry, and what is stored (as an array) in the session and in the `spid_profile` column.

## Claims mapping

| AAC claim | Property | Getter | Setter | Type |
|-----------|----------|--------|--------|------|
| `sub` | `sub` | `getSub()` | `setSub()` | string |
| `given_name` | `givenName` | `getGivenName()`, `getName()` | `setGivenName()` | string |
| `family_name` | `familyName` | `getFamilyName()`, `getSurname()` | `setFamilyName()` | string |
| `email` | `email` | `getEmail()` | `setEmail()` | string |
| `preferred_username` | `preferredUsername` | `getPreferredUsername()` | `setPreferredUsername()` | string |
| `locale` | `locale` | `getLocale()` | `setLocale()` | string |
| `zoneinfo` | `zoneinfo` | `getZoneinfo()` | `setZoneinfo()` | string |
| `realm` | `realm` | `getRealm()` | `setRealm()` | string |
| `id` | `id` | `getId()` | `setId()` | string |
| `enti-codicefiscale` | `entiCodiceFiscale` | `getEntiCodiceFiscale()` | `setEntiCodiceFiscale()` | array |
| `enti-spid` | `entiSpid` | `getEntiSpid()` | `setEntiSpid()` | array |
| `enti-acr` | `entiAcr` | `getEntiAcr()` | `setEntiAcr()` | array |
| `enti-issuersource` | `entiIssuerSource` | `getEntiIssuerSource()` | `setEntiIssuerSource()` | array |

Derived getter:

| Getter | Returns |
|--------|---------|
| `getFiscalNumber()` | `enti-codicefiscale.fiscalCode`, trimmed, or `''` |

All properties are private; setters return `$this` for chaining. The `enti-*` claim names are still to be confirmed against a real AAC response, see [KI-01](known-issues.md#ki-01-aac-claim-names-are-not-verified-against-a-real-userinfo-response).

## Example userinfo payload

Fake data, shaped like the payload the package expects:

```json
{
    "sub": "8f14e45f-ceea-467a-9575-0123456789ab",
    "given_name": "Mario",
    "family_name": "Rossi",
    "email": "mario.rossi@example.com",
    "preferred_username": "mario.rossi",
    "locale": "it",
    "zoneinfo": "Europe/Rome",
    "realm": "your-realm",
    "id": "user-123",
    "enti-codicefiscale": { "fiscalCode": "TINIT-RSSMRA80A01H501U", "id": "cf-1" },
    "enti-spid": { "isSpid": "true", "spidCode": "SPID0000000001", "id": "spid-1" },
    "enti-acr": { "acr": "https://www.spid.gov.it/SpidL2", "id": "acr-1" },
    "enti-issuersource": { "issuerSource": "https://idp.example.com", "id": "issuer-1" }
}
```

## Creating instances

```php
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

$user = new SpidTrentinoUser($arrayOrStdClass);
$user = SpidTrentinoUser::fromArray($array);
$user = SpidTrentinoUser::fromStdClass($object);      // nested objects are converted to arrays
$user = SpidTrentinoUser::fromJson($json);            // throws InvalidArgumentException on invalid JSON
$user = SpidTrentinoUser::fromJson($json, false);     // returns an empty user instead of throwing
```

`fromJson()` also rejects JSON that is not an object (for example `"text"` or `[]`).

## Hydration rules

Hydration is tolerant, so an unexpected payload never throws a `TypeError`:

- String claims: strings are kept; integers and floats are converted to strings; anything else (null, arrays, booleans) becomes `''`.
- `enti-*` claims: arrays (or objects) are kept as arrays; anything else becomes `[]`.
- Missing claims become `''` or `[]`.

## `toArray()` shape

`toArray()` (and `jsonSerialize()`) return 13 keys, in this order:

```php
[
    'sub' => '...',
    'zoneinfo' => '...',
    'enti-issuersource' => [...],
    'preferred_username' => '...',
    'locale' => '...',
    'given_name' => '...',
    'email' => '...',
    'enti-acr' => [...],
    'enti-spid' => [...],
    'realm' => '...',
    'enti-codicefiscale' => [...],
    'id' => '...',
    'family_name' => '...',
]
```

This array is what the package stores under `SessionKeys::USER` and in `spid_profile`, and `SpidTrentinoUser::fromArray()` reads it back.
