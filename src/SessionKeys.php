<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino;

/**
 * Every session key written by the package.
 */
final class SessionKeys
{
    /** Array payload of the SPID user (SpidTrentinoUser::toArray()). */
    public const string USER = 'spid_trentino_user';

    public const string ACCESS_TOKEN = 'spid_trentino_access_token';

    public const string REFRESH_TOKEN = 'spid_trentino_refresh_token';

    /** ISO-8601 expiry of the access token (read it with Support\SessionExpiry). */
    public const string ACCESS_TOKEN_EXPIRES_AT = 'spid_trentino_access_token_expires_at';

    /** Flash message set when the SPID login fails. */
    public const string ERROR = 'spid_trentino_error';

    /** Id grouping the transaction log records of one login (see Services\SpidTransactionLogger). */
    public const string TRANSACTION_ID = 'spid_trentino_transaction_id';

    /** Prefix of the OIDC state, nonce and PKCE verifier kept during the login round trip. */
    public const string OIDC_PREFIX = 'spid_trentino_oidc_';
}
