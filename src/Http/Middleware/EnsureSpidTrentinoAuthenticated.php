<?php
namespace OfflineAgency\SpidLaravelTrentino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSpidTrentinoAuthenticated
{
  public function handle(Request $request, Closure $next)
  {
    if (!auth()->check() || !session()->has('access_token')) {
      return $request->expectsJson()
        ? response()->json(['message' => 'Unauthenticated.'], 401)
        : redirect()->guest(route('aac.login'));
    }

    return $next($request);
  }
}
