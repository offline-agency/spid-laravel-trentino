<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RedirectResponse redirectToLogin()
 * @method static SpidTrentinoUser handleCallback()
 * @method static void refreshAccessToken()
 * @method static void logout()
 * @method static object|null getUserInfo()
 *
 * @see SpidTrentino
 */
class SpidTrentinoFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SpidTrentino::class;
    }
}
