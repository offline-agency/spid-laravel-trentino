<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\OpenIdConnect;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OfflineAgency\SpidLaravelTrentino\SessionKeys;

/**
 * jumbojett/openid-connect-php adapted to Laravel.
 *
 * - State, nonce and PKCE verifier are kept in the Laravel session, never in the native PHP session.
 * - Redirects throw an HttpResponseException instead of calling header() and exit.
 * - HTTP goes through the Laravel HTTP client; the discovery document and the
 *   JWKS are cached for $cacheTtl seconds.
 *
 * The ID token signature (keys from the discovered jwks_uri) and the iss, aud,
 * sub, nonce, exp, nbf and at_hash claims are verified by the parent inside
 * authenticate(). This class only adds the checks the parent misses (string
 * iss/sub, aud membership without a TypeError, mandatory exp) and does not
 * validate the token a second time.
 */
class LaravelOpenIDConnectClient extends OpenIDConnectClient
{
    /** Callback parameters the parent reads from $_REQUEST. */
    private const array CALLBACK_PARAMETERS = ['code', 'state', 'error', 'error_description'];

    private const string CACHE_PREFIX = 'spid-laravel-trentino:oidc:';

    private ?string $lastContentType = null;

    public function __construct(
        string $providerUrl,
        string $clientId,
        ?string $clientSecret = null,
        private readonly int $cacheTtl = 3600,
    ) {
        parent::__construct($providerUrl, $clientId, $clientSecret);
    }

    /**
     * Runs the code flow against the parameters of the current request.
     *
     * @throws OpenIDConnectClientException
     */
    public function authenticate(): bool
    {
        return $this->authenticateWith(Request::only(self::CALLBACK_PARAMETERS));
    }

    /**
     * Runs the code flow against the given callback parameters. The parent
     * reads $_REQUEST, so it is swapped for the whitelisted string parameters
     * and restored afterwards. Without a code or error parameter the parent
     * starts a new authorization request, which redirects.
     *
     * @param  array<array-key, mixed>  $parameters
     *
     * @throws OpenIDConnectClientException
     * @throws HttpResponseException with the redirect to the authorization endpoint
     */
    public function authenticateWith(array $parameters): bool
    {
        $globalRequest = $_REQUEST;
        $_REQUEST = array_filter(
            array_intersect_key($parameters, array_flip(self::CALLBACK_PARAMETERS)),
            is_string(...),
        );

        try {
            $authenticated = parent::authenticate();
        } finally {
            $_REQUEST = $globalRequest;
        }

        $this->unsetCodeVerifier();

        return $authenticated;
    }

    /**
     * Builds the redirect to the AAC authorization endpoint.
     *
     * @throws OpenIDConnectClientException
     */
    public function authorizationRedirect(): RedirectResponse
    {
        try {
            $this->authenticateWith([]);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();

            if ($response instanceof RedirectResponse) {
                return $response;
            }
        }

        throw new OpenIDConnectClientException('The authorization request did not produce a redirect.');
    }

    /**
     * @throws HttpResponseException always, carrying the redirect response
     */
    public function redirect(string $url): never
    {
        throw new HttpResponseException(new RedirectResponse($url));
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function verifyJWTSignature(string $jwt): bool
    {
        try {
            return parent::verifyJWTSignature($jwt);
        } catch (OpenIDConnectClientException $exception) {
            // AAC may have rotated its keys since the JWKS was cached.
            if (! $this->forgetCachedJwks()) {
                throw $exception;
            }

            return parent::verifyJWTSignature($jwt);
        }
    }

    public function getResponseContentType(): ?string
    {
        return $this->lastContentType;
    }

    /**
     * @param  mixed  $claims
     */
    protected function verifyJWTClaims($claims, ?string $accessToken = null): bool
    {
        if (! is_object($claims)
            || ! isset($claims->iss, $claims->sub)
            || ! is_string($claims->iss)
            || ! is_string($claims->sub)) {
            return false;
        }

        $audiences = isset($claims->aud) && is_array($claims->aud) ? $claims->aud : [$claims->aud ?? null];

        if (! in_array($this->getClientID(), $audiences, true)) {
            return false;
        }

        // OIDC Core 3.1.3.7: ID tokens must carry exp. The parent accepts tokens without it.
        if ($accessToken !== null && ! (isset($claims->exp) && is_int($claims->exp))) {
            return false;
        }

        return parent::verifyJWTClaims($claims, $accessToken);
    }

    /**
     * The parent assumes a well-formed token response; a gateway error page or
     * a response without tokens would surface as a TypeError or a PHP warning.
     *
     * @param  array<int, string>  $headers
     *
     * @throws OpenIDConnectClientException
     */
    protected function requestTokens(string $code, array $headers = []): object
    {
        $response = parent::requestTokens($code, $headers);

        if (! $response instanceof \stdClass) {
            throw new OpenIDConnectClientException("AAC returned an invalid token response (HTTP {$this->responseCode}).");
        }

        if (isset($response->error)) {
            return $response;
        }

        if (! isset($response->access_token, $response->id_token)
            || ! is_string($response->access_token)
            || ! is_string($response->id_token)) {
            throw new OpenIDConnectClientException('AAC returned a token response without access_token or id_token.');
        }

        return $response;
    }

    protected function startSession(): void
    {
        // The Laravel session is started by the StartSession middleware.
    }

    protected function commitSession(): void
    {
        // The Laravel session is saved by the StartSession middleware.
    }

    protected function getSessionKey(string $key): mixed
    {
        return Session::get(SessionKeys::OIDC_PREFIX.$key, false);
    }

    protected function setSessionKey(string $key, mixed $value): void
    {
        Session::put(SessionKeys::OIDC_PREFIX.$key, $value);
    }

    protected function unsetSessionKey(string $key): void
    {
        Session::forget(SessionKeys::OIDC_PREFIX.$key);
    }

    /**
     * Unauthenticated GETs (discovery document and JWKS) are cached and must
     * answer 200; other requests are returned as-is for the parent to inspect.
     *
     * @param  array<int, string>  $headers  "Name: value" lines
     *
     * @throws OpenIDConnectClientException
     */
    protected function fetchURL(string $url, ?string $post_body = null, array $headers = []): string
    {
        $isMetadata = $post_body === null && $headers === [];
        $cacheKey = self::CACHE_PREFIX.sha1($url);

        if ($isMetadata && $this->cacheTtl > 0) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached)) {
                $this->responseCode = 200;
                $this->lastContentType = 'application/json';

                return $cached;
            }
        }

        $body = $this->send($url, $post_body, $headers);

        if ($isMetadata && $this->responseCode !== 200) {
            throw new OpenIDConnectClientException("AAC returned HTTP {$this->responseCode} for {$url}");
        }

        if ($isMetadata && $this->cacheTtl > 0) {
            Cache::put($cacheKey, $body, $this->cacheTtl);
        }

        return $body;
    }

    /**
     * @param  array<int, string>  $headers
     *
     * @throws OpenIDConnectClientException
     */
    private function send(string $url, ?string $body, array $headers): string
    {
        $request = Http::withUserAgent($this->getUserAgent())
            ->timeout($this->getTimeout())
            ->withHeaders($this->parseHeaders($headers));

        try {
            $response = $body === null
                ? $request->get($url)
                : $request->withBody($body, $this->contentTypeFor($body))->post($url);
        } catch (ConnectionException $exception) {
            throw new OpenIDConnectClientException('Unable to reach AAC: '.$exception->getMessage(), 0, $exception);
        }

        $this->responseCode = $response->status();
        $this->lastContentType = $response->header('Content-Type') ?: null;

        return $response->body();
    }

    private function forgetCachedJwks(): bool
    {
        $jwksUri = $this->getProviderConfigValue('jwks_uri');

        return is_string($jwksUri) && Cache::forget(self::CACHE_PREFIX.sha1($jwksUri));
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    private function parseHeaders(array $headers): array
    {
        $parsed = [];

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $parsed[trim($name)] = trim($value);
        }

        return $parsed;
    }

    private function contentTypeFor(string $body): string
    {
        return is_object(json_decode($body)) ? 'application/json' : 'application/x-www-form-urlencoded';
    }
}
