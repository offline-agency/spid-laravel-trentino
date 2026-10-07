<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Exceptions;

use Jumbojett\OpenIDConnectClientException;

/**
 * Thrown when transaction_log.fail_closed is on and a transaction log row
 * cannot be written. It extends the OIDC client exception so the login and
 * callback routes handle it like any other failed login. The database error
 * is not chained: its message contains the bound values.
 */
class TransactionLogUnavailable extends OpenIDConnectClientException
{
    public function __construct(public readonly string $eventType)
    {
        parent::__construct("The SPID transaction log could not be written ({$eventType}).");
    }
}
