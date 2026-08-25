<?php

namespace WHMCS\Module\Addon\VpnHoodSignIn;

if (!defined('WHMCS') && !defined('VPNHOODSIGNIN_TEST')) {
    die('This file cannot be accessed directly');
}

/**
 * Minimal JWT: RS256 verification via openssl. No Composer, no other deps.
 *
 * This module verifies exactly one kind of token — the Google ID token that
 * Google Identity Services hands to the browser and that we receive back from
 * our own page script. The browser is never trusted with anything else, so
 * this is the whole trust boundary of the module and it is deliberately small.
 *
 * Only RS256 is supported. The alg header is pinned before any cryptography
 * happens, so alg-confusion forgeries ("none", or HS256 using the public key
 * as the HMAC secret) fail on the very first check rather than deep inside
 * openssl.
 *
 * Adapted from the equivalent class in the VpnHood.WHMCS.Iap repo. It is
 * copied rather than shared on purpose: this module must install and run on a
 * WHMCS that has no other VpnHood module present.
 */
final class Jwt
{
    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict decoder: rejects any input that is not canonical base64url. */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = strtr($data, '-_', '+/') . ($remainder > 0 ? str_repeat('=', 4 - $remainder) : '');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url data in token.');
        }
        return $decoded;
    }

    /**
     * Split and decode a compact JWT. No verification happens here — callers
     * must not read the claims this returns without calling verifyRs256.
     *
     * @return array{header:array, claims:array, signature:string, signedPart:string}
     */
    public static function parse(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token: expected three segments.');
        }
        [$headerB64, $claimsB64, $signatureB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headerB64), true);
        $claims = json_decode(self::base64UrlDecode($claimsB64), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new \RuntimeException('Malformed token: header/claims are not JSON objects.');
        }

        return [
            'header'     => $header,
            'claims'     => $claims,
            'signature'  => self::base64UrlDecode($signatureB64),
            'signedPart' => $headerB64 . '.' . $claimsB64,
        ];
    }

    /**
     * Verify an RS256 JWT against a set of PEM public keys or X.509 certs,
     * keyed by kid. When the token names a kid present in the set only that
     * key is tried; otherwise every key is. Returns the verified claims.
     *
     * Time validity (exp/nbf/iat) is NOT checked here — call assertTimeValid.
     *
     * @param array<string,string> $pemKeysByKid kid => PEM (public key or certificate)
     * @throws \RuntimeException when malformed, not RS256, or unsigned by any key
     */
    public static function verifyRs256(string $jwt, array $pemKeysByKid): array
    {
        $parsed = self::parse($jwt);

        $alg = $parsed['header']['alg'] ?? '';
        if ($alg !== 'RS256') {
            throw new \RuntimeException("Unsupported token algorithm: '$alg' (only RS256 is accepted).");
        }

        $kid = $parsed['header']['kid'] ?? null;
        $candidates = is_string($kid) && isset($pemKeysByKid[$kid])
            ? [$kid => $pemKeysByKid[$kid]]
            : $pemKeysByKid;
        if ($candidates === []) {
            throw new \RuntimeException('No verification keys available.');
        }

        foreach ($candidates as $pem) {
            $publicKey = openssl_pkey_get_public($pem);
            if ($publicKey === false) {
                continue; // an unparsable key must not veto the others
            }
            if (openssl_verify($parsed['signedPart'], $parsed['signature'], $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return $parsed['claims'];
            }
        }

        throw new \RuntimeException('Token signature verification failed.');
    }

    /**
     * Assert exp/nbf/iat sanity with clock-skew leeway. exp is mandatory:
     * a Google ID token always carries one, and a missing exp would otherwise
     * mean a token that never stops being accepted.
     */
    public static function assertTimeValid(array $claims, ?int $now = null, int $leewaySeconds = 300): void
    {
        $now ??= time();

        $exp = $claims['exp'] ?? null;
        if (!is_numeric($exp)) {
            throw new \RuntimeException('Token has no expiry.');
        }
        if ($now > (int) $exp + $leewaySeconds) {
            throw new \RuntimeException('Token is expired.');
        }
        $nbf = $claims['nbf'] ?? null;
        if (is_numeric($nbf) && $now < (int) $nbf - $leewaySeconds) {
            throw new \RuntimeException('Token is not valid yet.');
        }
        $iat = $claims['iat'] ?? null;
        if (is_numeric($iat) && $now < (int) $iat - $leewaySeconds) {
            throw new \RuntimeException('Token is issued in the future.');
        }
    }
}
