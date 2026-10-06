<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use OfflineAgency\SpidLaravelTrentino\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Makes the given query the current request, as the callback route would.
 *
 * @param  array<string, mixed>  $query
 */
function useCallbackRequest(array $query): void
{
    app()->instance('request', Request::create('/spid/callback', 'GET', $query));
    Facade::clearResolvedInstance('request');
}
