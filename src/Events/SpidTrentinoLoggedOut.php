<?php

namespace OfflineAgency\SpidLaravelTrentino\Events;


use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

class SpidTrentinoLoggedOut
{
  use Dispatchable, SerializesModels;

  public $user;

  public function __construct($user)
  {
    $this->user = $user;
  }
}
