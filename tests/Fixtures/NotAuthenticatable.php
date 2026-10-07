<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class NotAuthenticatable extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
