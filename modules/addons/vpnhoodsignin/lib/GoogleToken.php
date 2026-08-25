<?php

namespace WHMCS\Module\Addon\VpnHoodSignIn;

require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/Http.php';

if (!defined('WHMCS') && !defined('VPNHOODSIGNIN_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Verifies the Google ID token that "Sign in with Google" produces.
 *
 * This is the module's entire trust boundary. Our page script hands us the
 * `credentialResponse.credential` that WHMCS itself posts to
 * /index.php?rp=/auth/provider/google_signin/finalize — with one addition:
 * we seed google.accounts.id.initialize() with a per-session nonce, so the
 * token Google mints carries it as a claim. The token still arrives from the
 * browser, and must be treated as hostile until every one of these passes:
 *
 *   1. RS256 signature against Google's published X.509 certs (alg pinned)
 *   2. exp / nbf / iat within clock-skew leeway
 *   3. iss is one of Google's two spellings
 *   4. aud is the Google Client ID this WHMCS is configured with — without
 *      this, a token minted for ANY other Google OAuth app would be accepted,
 *      which is the difference between "signed by Google" and "meant for us"
 *   5. nonce is the one this session handed to Google — OpenID Connect's
 *      §3.2.2.11 binding. aud proves the token was minted for this SITE;
 *      nonce proves it was minted for THIS sign-in, so a token captured from
 *      another browser cannot be replayed here during its hour of life.
 *      Without it, an ID token is a bearer credential for that whole hour
 *   6. a non-empty sub and a syntactically valid email
 *   7. email_verified — an unverified Google address proves no mailbox
 *      ownership, and every decision this module makes keys off the address
 *
 * The certs fetcher is injectable so the unit tests can drive the whole
 * pipeline against runtime-generated keys with no network.
 */
class GoogleToken
{
    /** WHMCS's own provider id for this integration; also our tblauthn_account_links key. */
    public const PROVIDER = 'google_signin';

    /** kid => X.509 PEM. Rotated by Google; fetched once per process. */
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    /** @var callable(): array<string,string> */
    private $certsFetcher;
    private ?int $now;

    /**
     * @param callable():array<string,string>|null $certsFetcher kid => PEM map; null = fetch from Google
     * @param ?int $now clock override for tests
     */
    public function __construct(?callable $certsFetcher = null, ?int $now = null)
    {
        $this->certsFetcher = $certsFetcher ?? [self::class, 'fetchGoogleCerts'];
        $this->now = $now;
    }

    /**
     * $expectedNonce is required rather than optional on purpose: a caller that
     * forgets it must fail loudly, never silently verify one check fewer.
     *
     * @param string $idToken the compact JWT from credentialResponse.credential
     * @param string $expectedAudience the configured Google OAuth Client ID
     * @param string $expectedNonce the nonce this session gave the GSI button
     * @return array{subject:string, email:string, firstName:string, lastName:string, name:?string, claims:array}
     * @throws \RuntimeException on any failed check
     */
    public function verify(string $idToken, string $expectedAudience, string $expectedNonce): array
    {
        $expectedAudience = trim($expectedAudience);
        if ($expectedAudience === '') {
            throw new \RuntimeException('No Google Client ID is configured, so no token audience can be trusted.');
        }

        $expectedNonce = trim($expectedNonce);
        if ($expectedNonce === '') {
            throw new \RuntimeException('No session nonce was supplied, so no token nonce can be trusted.');
        }

        $claims = Jwt::verifyRs256($idToken, ($this->certsFetcher)());
        Jwt::assertTimeValid($claims, $this->now);

        $issuer = (string) ($claims['iss'] ?? '');
        if (!in_array($issuer, self::ISSUERS, true)) {
            throw new \RuntimeException("Unexpected token issuer: '$issuer'.");
        }

        if (!hash_equals($expectedAudience, (string) ($claims['aud'] ?? ''))) {
            throw new \RuntimeException('Token audience is not the Google Client ID of this installation.');
        }

        // A token with no nonce claim is one Google minted for a page that never
        // asked for one — a tab left open from before this was deployed, or a
        // token lifted from an integration that omits it. Neither is bound to
        // this session, so neither is accepted.
        if (!hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new \RuntimeException('Token nonce does not match this session; it was not minted for this sign-in.');
        }

        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            throw new \RuntimeException('Token has no subject.');
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Token has no usable email claim.');
        }

        // Google reports this as a real boolean; be tolerant of the string
        // spellings other IdPs use rather than silently reading them as true.
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true' && $verified !== 1 && $verified !== '1') {
            throw new \RuntimeException('Google has not verified this email address.');
        }

        [$firstName, $lastName] = self::splitName(
            isset($claims['given_name']) ? (string) $claims['given_name'] : '',
            isset($claims['family_name']) ? (string) $claims['family_name'] : '',
            isset($claims['name']) ? (string) $claims['name'] : ''
        );

        return [
            'subject'   => $subject,
            'email'     => $email,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'name'      => isset($claims['name']) ? (string) $claims['name'] : null,
            'claims'    => $claims,
        ];
    }

    /**
     * WHMCS requires both name parts non-empty. Google usually sends
     * given_name/family_name; when it sends only the display name we split
     * that, and when it sends nothing usable we fall back to a neutral
     * placeholder the client can correct on their own profile page.
     *
     * @return array{string, string}
     */
    public static function splitName(string $givenName, string $familyName, string $displayName): array
    {
        $first = trim($givenName);
        $last = trim($familyName);

        if ($first === '' || $last === '') {
            $parts = preg_split('/\s+/', trim($displayName), 2) ?: [];
            if ($first === '') {
                $first = trim((string) ($parts[0] ?? ''));
            }
            if ($last === '') {
                $last = trim((string) ($parts[1] ?? ''));
            }
        }

        return [
            $first !== '' ? $first : 'VpnHood',
            $last !== '' ? $last : 'Customer',
        ];
    }

    /** @return array<string,string> kid => X.509 PEM */
    public static function fetchGoogleCerts(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $response = Http::request('GET', self::CERTS_URL);
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            throw new \RuntimeException('Could not fetch Google signing certificates (HTTP ' . $response['status'] . ').');
        }
        $certs = array_filter($response['json'], 'is_string');
        if ($certs === []) {
            throw new \RuntimeException('Google signing certificate response is empty.');
        }
        return $cache = $certs;
    }
}
