<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Tests\Fixtures;

class AdminUser extends User
{
    public function hasRole(string $role): bool
    {
        return $role === 'admin';
    }
}
