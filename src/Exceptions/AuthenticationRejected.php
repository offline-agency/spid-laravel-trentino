<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Exceptions;

use Jumbojett\OpenIDConnectClientException;

/**
 * Thrown when AAC authenticated the user but the login does not meet the
 * configured policy: the SPID level is below required_acr (or unknown), or
 * the identity provider is not in allowed_issuer_sources. It extends the OIDC
 * client exception so the callback route handles it like a failed login.
 */
class AuthenticationRejected extends OpenIDConnectClientException
{
    public const string REASON_ACR = 'acr';

    public const string REASON_ISSUER_SOURCE = 'issuer_source';

    /**
     * @param  self::REASON_*  $reason
     */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * Message shown to the user (the exception message is for the log).
     */
    public function userMessage(): string
    {
        return $this->reason === self::REASON_ACR
            ? 'Your SPID login does not meet the security level required by this service.'
            : 'Your identity provider is not accepted by this service.';
    }
}
