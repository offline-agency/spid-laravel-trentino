<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Support\Facades\Facade;

class SpidTrentinoFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'spid-trentino';
    }
}
