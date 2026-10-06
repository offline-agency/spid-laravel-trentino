<?php

namespace OfflineAgency\SpidLaravelTrentino\Testing;

use Jumbojett\OpenIDConnectClient;

class MockOpenIDConnectClient extends OpenIDConnectClient
{
    public function authenticate(): bool
    {
        return true;
    }

    public function getAccessToken(): string
    {
        return 'mock-access-token';
    }

    public function getRefreshToken(): string
    {
        return 'mock-refresh-token';
    }

    public function getIdToken(): string
    {
        return 'mock-id-token';
    }

    public function requestUserInfo(): object
    {
        return (object) [
            'sub' => 'u_test123',
            'email' => 'test@example.com',
            'given_name' => 'Mario',
            'family_name' => 'Rossi',
            'codicefiscale' => ['fiscalCode' => 'TINIT-MOCKCF123456'],
        ];
    }
}
