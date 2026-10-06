<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Events;

use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

/**
 * Dispatched after SpidTrentino::logout() ended a SPID session.
 * The DTO is a plain serializable object, so SerializesModels is not needed.
 */
class SpidTrentinoLoggedOut
{
    public function __construct(public readonly SpidTrentinoUser $user) {}

    public function getUser(): SpidTrentinoUser
    {
        return $this->user;
    }
}
