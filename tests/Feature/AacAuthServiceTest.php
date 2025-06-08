<?php

use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\SpidTrentino;
use Tests\TestCase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Auth;
use Jumbojett\OpenIDConnectClient;

uses(TestCase::class);

beforeEach(function () {
  Session::start();

  $this->mock = mock(OpenIDConnectClient::class)->makePartial();
  $this->mock->shouldReceive('authenticate')->andReturnTrue();
  $this->mock->shouldReceive('getAccessToken')->andReturn('access-token');
  $this->mock->shouldReceive('getRefreshToken')->andReturn('refresh-token');
  $this->mock->shouldReceive('getIdToken')->andReturn('id-token');
  $this->mock->shouldReceive('requestUserInfo')->andReturn((object) [
    'sub' => 'user123',
    'email' => 'test@example.com',
    'given_name' => 'John',
    'family_name' => 'Doe',
    'codicefiscale' => (object) ['fiscalCode' => 'XYZ123']
  ]);

  app()->bind(OpenIDConnectClient::class, fn () => $this->mock);

  app()->bind('user.resolver', fn () => new class {
    public function resolveOrCreate(array $info) {
      return new class {
        public $id = 123;
      };
    }
  });
});

it('handles callback and authenticates user', function () {
  Event::fake();
  Auth::shouldReceive('login')->once();

  $service = app(SpidTrentino::class);
  $service->handleCallback();

  expect(Session::get('access_token'))->toBe('access-token');
  expect(Session::get('refresh_token'))->toBe('refresh-token');

  Event::assertDispatched(SpidTrentinoLoggedIn::class);
});

it('refreshes tokens if refresh_token exists', function () {
  Session::put('refresh_token', 'refresh-token');
  $service = app(SpidTrentino::class);
  $service->refreshAccessToken();

  expect(Session::get('access_token'))->toBe('access-token');
  expect(Session::get('refresh_token'))->toBe('refresh-token');
});

it('does nothing if refresh_token is missing', function () {
  Session::forget('refresh_token');
  $mock = mock(OpenIDConnectClient::class)->makePartial();
  $mock->shouldNotReceive('refreshToken');
  app()->bind(OpenIDConnectClient::class, fn () => $mock);

  $service = app(SpidTrentino::class);
  $service->refreshAccessToken();

  expect(Session::get('access_token'))->toBeNull();
});

it('logs out and dispatches logout event', function () {
  Event::fake();

  Auth::shouldReceive('user')->andReturn(new class {
    public $id = 999;
  });
  Auth::shouldReceive('logout')->once();
  Session::put('access_token', 'xxx');

  $service = app(SpidTrentino::class);
  $service->logout();

  expect(Session::get('access_token'))->toBeNull();
  Event::assertDispatched(SpidTrentinoLoggedOut::class);
});
