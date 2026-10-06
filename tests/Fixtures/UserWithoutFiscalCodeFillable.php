<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

/**
 * An application model that forgot fiscal_code in $fillable.
 */
class UserWithoutFiscalCodeFillable extends User
{
    protected $fillable = ['name', 'email', 'password', 'surname', 'preferred_username', 'locale', 'zoneinfo'];
}
