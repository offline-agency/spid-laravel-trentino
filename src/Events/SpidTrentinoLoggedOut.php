<?php

namespace OfflineAgency\SpidLaravelTrentino\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

class SpidTrentinoLoggedOut
{
    use Dispatchable, SerializesModels;

    public SpidTrentinoUser $user;

    public function __construct($user)
    {
        $this->setUser($user);
    }

    public function getUser(): SpidTrentinoUser
    {
        return $this->user;
    }

    public function setUser(SpidTrentinoUser $user): void
    {
        $this->user = $user;
    }
}
