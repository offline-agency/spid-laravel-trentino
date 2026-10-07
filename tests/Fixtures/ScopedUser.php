<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/**
 * User model with a global scope hiding some rows, like SoftDeletes or a
 * tenant scope does.
 */
class ScopedUser extends User
{
    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn (Builder $query) => $query->where('name', '!=', 'Hidden'));
    }
}
