<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Events;

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

/**
 * Dispatched after a successful AAC callback, before the local user is authenticated.
 * The DTO is a plain serializable object, so SerializesModels is not needed.
 */
class SpidTrentinoLoggedIn
{
    public function __construct(public readonly SpidTrentinoUser $user) {}

    public function getUser(): SpidTrentinoUser
    {
        return $this->user;
    }
}
