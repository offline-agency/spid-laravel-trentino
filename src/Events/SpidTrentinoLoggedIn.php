<?php

namespace OfflineAgency\SpidLaravelTrentino\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

class SpidTrentinoLoggedIn
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
