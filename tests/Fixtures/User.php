<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'fiscal_code',
        'surname',
        'preferred_username',
        'locale',
        'zoneinfo',
    ];

    protected function casts(): array
    {
        return ['spid_profile' => 'array'];
    }
}
