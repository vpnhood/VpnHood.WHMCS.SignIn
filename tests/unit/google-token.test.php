<?php
/**
 * google-token.test.php — the whole token trust boundary, driven against a
 * runtime-generated RSA key injected as "Google's certs", so the full pipeline
 * (signature -> time -> issuer -> audience -> nonce -> claims) runs with no
 * network.
 *
 * Every check in GoogleToken::verify has a negative case here. That matters
 * more than usual for this module: the token is the ONLY thing standing
 * between a stranger and an auto-created (or auto-linked) WHMCS account.
 */

require_once SIGNIN_LIB . '/Jwt.php';
require_once SIGNIN_LIB . '/GoogleToken.php';

use WHMCS\Module\Addon\VpnHoodSignIn\GoogleToken;
use WHMCS\Module\Addon\VpnHoodSignIn\Jwt;

const AUD = '70395360206-4omhjcijhdtc7behnikgb32p1r0fi7kr.apps.googleusercontent.com';
const KID = 'test-kid-1';
const NONCE = '4f1c9a7e0b3d5628a1e7c4d9f0b2a835';

[$privatePem, $publicPem] = newTestRsaKey();

/** The certs fetcher GoogleToken will be given: kid => PEM. */
$certs = static fn (): array => [KID => $publicPem];

/** Sign a claim set as Google would. */
$mint = static function (array $overrides = []) use ($privatePem): string {
    $now = time();
    $claims = array_merge([
        'iss'            => 'https://accounts.google.com',
        'aud'            => AUD,
        'sub'            => '116003534307895373428',
        'email'          => 'Alex.Vito645@gmail.com',
        'email_verified' => true,
        'nonce'          => NONCE,
        'given_name'     => 'alex',
        'family_name'    => 'vito',
        'name'           => 'alex vito',
        'iat'            => $now,
        'nbf'            => $now,
        'exp'            => $now + 3600,
    ], $overrides);

    foreach ($overrides as $claimName => $value) {
        if ($value === null) {
            unset($claims[$claimName]);
        }
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => KID];
    $signedPart = Jwt::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . Jwt::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

    $signature = '';
    openssl_sign($signedPart, $signature, openssl_pkey_get_private($privatePem), OPENSSL_ALGO_SHA256);

    return $signedPart . '.' . Jwt::base64UrlEncode($signature);
};

// ============================================================ happy path ==

test('verify: a well-formed Google token yields the identity', function () use ($mint, $certs) {
    $result = (new GoogleToken($certs))->verify($mint(), AUD, NONCE);

    assertSame('116003534307895373428', $result['subject']);
    assertSame('alex.vito645@gmail.com', $result['email'], 'email is normalised to lowercase');
    assertSame('alex', $result['firstName']);
    assertSame('vito', $result['lastName']);
});

// ============================================================== signature ==

test('verify: a tampered payload is rejected', function () use ($mint, $certs) {
    [$header, $claims, $signature] = explode('.', $mint());
    $forged = json_decode(Jwt::base64UrlDecode($claims), true);
    $forged['email'] = 'victim@vpnhood.com';
    $tampered = $header . '.' . Jwt::base64UrlEncode(json_encode($forged)) . '.' . $signature;

    assertThrows(
        fn () => (new GoogleToken($certs))->verify($tampered, AUD, NONCE),
        \RuntimeException::class,
        'signature verification failed'
    );
});

test('verify: a token signed by a different key is rejected', function () use ($mint, $certs) {
    [$otherPrivatePem] = newTestRsaKey();

    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => KID];
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => AUD, 'sub' => 'x',
               'email' => 'a@b.com', 'email_verified' => true, 'exp' => time() + 3600];
    $signedPart = Jwt::base64UrlEncode(json_encode($header)) . '.' . Jwt::base64UrlEncode(json_encode($claims));
    $signature = '';
    openssl_sign($signedPart, $signature, openssl_pkey_get_private($otherPrivatePem), OPENSSL_ALGO_SHA256);

    assertThrows(
        fn () => (new GoogleToken($certs))->verify($signedPart . '.' . Jwt::base64UrlEncode($signature), AUD, NONCE),
        \RuntimeException::class,
        'signature verification failed'
    );
});

test('verify: alg confusion (none / HS256) is rejected before any crypto', function () use ($certs) {
    $claims = ['iss' => 'https://accounts.google.com', 'aud' => AUD, 'sub' => 'x',
               'email' => 'a@b.com', 'email_verified' => true, 'exp' => time() + 3600];

    foreach (['none', 'HS256'] as $alg) {
        $token = Jwt::base64UrlEncode(json_encode(['alg' => $alg, 'typ' => 'JWT']))
            . '.' . Jwt::base64UrlEncode(json_encode($claims)) . '.';

        assertThrows(
            fn () => (new GoogleToken($certs))->verify($token, AUD, NONCE),
            \RuntimeException::class,
            'Unsupported token algorithm'
        );
    }
});

test('verify: a malformed token is rejected', function () use ($certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify('not-a-jwt', AUD, NONCE),
        \RuntimeException::class,
        'expected three segments'
    );
});

// =================================================================== time ==

test('verify: an expired token is rejected once past the skew leeway', function () use ($mint, $certs) {
    $token = $mint(['iat' => time() - 7200, 'nbf' => time() - 7200, 'exp' => time() - 3600]);

    assertThrows(
        fn () => (new GoogleToken($certs))->verify($token, AUD, NONCE),
        \RuntimeException::class,
        'expired'
    );
});

test('verify: a token with no expiry is rejected', function () use ($mint, $certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['exp' => null]), AUD, NONCE),
        \RuntimeException::class,
        'no expiry'
    );
});

// ================================================== issuer / audience ==

test('verify: both of Google issuer spellings are accepted', function () use ($mint, $certs) {
    foreach (['https://accounts.google.com', 'accounts.google.com'] as $issuer) {
        $result = (new GoogleToken($certs))->verify($mint(['iss' => $issuer]), AUD, NONCE);
        assertSame('116003534307895373428', $result['subject']);
    }
});

test('verify: a foreign issuer is rejected', function () use ($mint, $certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['iss' => 'https://evil.example']), AUD, NONCE),
        \RuntimeException::class,
        'Unexpected token issuer'
    );
});

test('verify: a token minted for another Google app is rejected', function () use ($mint, $certs) {
    // Signed by Google, valid, in date — but issued to somebody else. This is
    // the check that separates "Google signed it" from "it was meant for us".
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['aud' => 'someone-else.apps.googleusercontent.com']), AUD, NONCE),
        \RuntimeException::class,
        'audience'
    );
});

test('verify: an unconfigured Client ID refuses everything', function () use ($mint, $certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(), '   ', NONCE),
        \RuntimeException::class,
        'No Google Client ID is configured'
    );
});

// ================================================================= nonce ==

test('verify: a token minted for another session is rejected', function () use ($mint, $certs) {
    // Signed by Google, in date, and minted for THIS site's Client ID — but
    // carrying somebody else's nonce. This is the replay case: an attacker who
    // captured a victim's token loads the login page to mint a nonce of their
    // own, and posts the stolen token with it. aud cannot tell the difference.
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(), AUD, 'a-different-session-nonce'),
        \RuntimeException::class,
        'nonce does not match'
    );
});

test('verify: a token carrying no nonce claim at all is rejected', function () use ($mint, $certs) {
    // A tab opened before this binding shipped, or a token from an integration
    // that never asked Google for a nonce. Unbound is unbound either way.
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['nonce' => null]), AUD, NONCE),
        \RuntimeException::class,
        'nonce does not match'
    );
});

test('verify: a token whose nonce is the empty string is rejected', function () use ($mint, $certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['nonce' => '']), AUD, NONCE),
        \RuntimeException::class,
        'nonce does not match'
    );
});

test('verify: a missing session nonce refuses everything', function () use ($mint, $certs) {
    // The caller forgot, or the session lost it. Either way there is nothing to
    // bind against, and a blank expectation must never be satisfiable.
    foreach (['', '   '] as $sessionNonce) {
        assertThrows(
            fn () => (new GoogleToken($certs))->verify($mint(['nonce' => $sessionNonce]), AUD, $sessionNonce),
            \RuntimeException::class,
            'No session nonce was supplied'
        );
    }
});

// ================================================================ claims ==

test('verify: an unverified Google address is rejected', function () use ($mint, $certs) {
    foreach ([false, 'false', 0, null] as $value) {
        assertThrows(
            fn () => (new GoogleToken($certs))->verify($mint(['email_verified' => $value]), AUD, NONCE),
            \RuntimeException::class,
            'not verified this email'
        );
    }
});

test('verify: the string spelling of email_verified is accepted', function () use ($mint, $certs) {
    $result = (new GoogleToken($certs))->verify($mint(['email_verified' => 'true']), AUD, NONCE);
    assertSame('alex.vito645@gmail.com', $result['email']);
});

test('verify: a missing or nonsense email is rejected', function () use ($mint, $certs) {
    foreach ([null, '', 'not-an-email'] as $value) {
        assertThrows(
            fn () => (new GoogleToken($certs))->verify($mint(['email' => $value]), AUD, NONCE),
            \RuntimeException::class,
            'usable email'
        );
    }
});

test('verify: a missing subject is rejected', function () use ($mint, $certs) {
    assertThrows(
        fn () => (new GoogleToken($certs))->verify($mint(['sub' => null]), AUD, NONCE),
        \RuntimeException::class,
        'no subject'
    );
});

// ================================================================= names ==

test('splitName: given/family names are used as-is', function () {
    assertSame(['Alex', 'Vito'], GoogleToken::splitName('Alex', 'Vito', 'ignored entirely'));
});

test('splitName: a display-name-only token is split', function () {
    assertSame(['Alex', 'Van Vito'], GoogleToken::splitName('', '', 'Alex Van Vito'));
});

test('splitName: a single-word name gets the placeholder surname', function () {
    assertSame(['Alex', 'Customer'], GoogleToken::splitName('', '', 'Alex'));
});

test('splitName: a nameless token still yields two non-empty parts', function () {
    // WHMCS rejects an empty firstname/lastname even with skipvalidation.
    assertSame(['VpnHood', 'Customer'], GoogleToken::splitName('', '', '   '));
});

test('verify: a token carrying only a display name still produces both parts', function () use ($mint, $certs) {
    $result = (new GoogleToken($certs))->verify(
        $mint(['given_name' => null, 'family_name' => null, 'name' => 'Alex Van Vito']),
        AUD,
        NONCE
    );
    assertSame('Alex', $result['firstName']);
    assertSame('Van Vito', $result['lastName']);
});
