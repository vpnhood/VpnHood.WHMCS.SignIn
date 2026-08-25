<?php

/**
 * VpnHood! Sign-In — the endpoint the sign-in button posts to.
 *
 *   POST https://<whmcs>/modules/addons/vpnhoodsignin/api.php
 *   body: id_token=<Google credential JWT>&nonce=<per-session nonce>
 *
 * Answers JSON:
 *   { "status": "logged_in",  "redirectUrl": "..." }   sign in and follow the URL
 *   { "status": "two_factor", "message": "..." }       account uses WHMCS 2FA
 *   { "status": "refused",    "message": "..." }       show the message, stay put
 *
 * FAILS CLOSED: while the addon is not activated, every request is answered 404.
 * The module must expose nothing until an admin has activated and configured it.
 *
 * The only thing this endpoint trusts is the Google ID token, and only after
 * GoogleToken::verify has checked its signature, age, issuer, that its audience
 * is this installation's own Google Client ID, and that its nonce claim is the
 * one this session handed to Google.
 *
 * The session nonce is checked TWICE, for two different jobs:
 *
 *   - as a posted form field, here, before any cryptography. A cheap gate that
 *     keeps a stranger's probe from costing us a JWT verification and an
 *     outbound fetch of Google's certs, and that blocks login-CSRF (a stranger
 *     silently signing a visitor into an account of the stranger's choosing).
 *
 *   - as the token's own signed `nonce` claim, inside verify(). This is the one
 *     that matters: Google signed that claim, so unlike the form field it cannot
 *     be chosen by whoever is posting. It binds the token to this session, which
 *     is what stops a token captured from someone else's browser being replayed
 *     here by an attacker who simply loaded the login page to mint their own.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodSignIn\AccountLinker;
use WHMCS\Module\Addon\VpnHoodSignIn\GoogleToken;
use WHMCS\Module\Addon\VpnHoodSignIn\SignInGate;

// Bootstrap WHMCS (gives us Capsule, localAPI, models, sessions).
require_once __DIR__ . '/../../../init.php';

require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/GoogleToken.php';
require_once __DIR__ . '/lib/SignInGate.php';
require_once __DIR__ . '/lib/AccountLinker.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * @param array<string,mixed> $payload
 */
function vpnhoodsignin_respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function vpnhoodsignin_isActive(): bool
{
    try {
        return Capsule::table('tbladdonmodules')
            ->where('module', SignInGate::MODULE)
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
}

if (!vpnhoodsignin_isActive()) {
    vpnhoodsignin_respond(404, ['status' => 'refused', 'message' => 'Not found.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    vpnhoodsignin_respond(405, ['status' => 'refused', 'message' => 'Method not allowed.']);
}

$settings = SignInGate::settings();
if (!$settings['enabled']) {
    vpnhoodsignin_respond(404, ['status' => 'refused', 'message' => 'Not found.']);
}

// First pass over the nonce: a cheap gate, before any crypto or network. The
// nonce is minted per session when the button is rendered; a request that cannot
// echo it back did not come from a page we drew. The binding that actually
// matters is the signed `nonce` claim, checked inside verify() below.
$expectedNonce = (string) ($_SESSION['vpnhoodsignin_nonce'] ?? '');
$providedNonce = (string) ($_POST['nonce'] ?? '');
if ($expectedNonce === '' || !hash_equals($expectedNonce, $providedNonce)) {
    SignInGate::log('api.badNonce', ['ip' => SignInGate::clientIp()], 'nonce missing or mismatched');
    vpnhoodsignin_respond(403, [
        'status'  => 'refused',
        'message' => 'Your session expired. Please reload the page and try again.',
    ]);
}

$idToken = trim((string) ($_POST['id_token'] ?? ''));
if ($idToken === '') {
    vpnhoodsignin_respond(400, ['status' => 'refused', 'message' => 'No sign-in token was supplied.']);
}

try {
    $identity = (new GoogleToken())->verify($idToken, $settings['clientId'], $expectedNonce);
} catch (\Throwable $e) {
    // The reason is for the admin, never for the visitor: a stranger probing
    // this endpoint learns nothing about why their token was rejected.
    SignInGate::log('api.tokenRejected', ['ip' => SignInGate::clientIp()], $e->getMessage());
    vpnhoodsignin_respond(401, [
        'status'  => 'refused',
        'message' => 'We could not verify your Google sign-in. Please try again.',
    ]);
}

try {
    $result = (new AccountLinker())->signIn($identity);
} catch (\Throwable $e) {
    SignInGate::log('api.signInFailed', ['email' => $identity['email']], $e->getMessage());
    vpnhoodsignin_respond(500, [
        'status'  => 'refused',
        'message' => 'Something went wrong signing you in. Please try again in a moment.',
    ]);
}

// A fresh session is about to begin (or the attempt failed); either way the
// nonce has been spent and must not be replayable.
unset($_SESSION['vpnhoodsignin_nonce']);

$status = $result['status'] ?? AccountLinker::STATUS_REFUSED;
SignInGate::log('api.signIn', [
    'email'  => $identity['email'],
    'sub'    => $identity['subject'],
], [
    'status'   => $status,
    'clientId' => $result['clientId'] ?? null,
    'created'  => $result['created'] ?? false,
]);

if ($status === AccountLinker::STATUS_LOGGED_IN) {
    vpnhoodsignin_respond(200, [
        'status'      => $status,
        'redirectUrl' => $result['redirectUrl'],
    ]);
}

vpnhoodsignin_respond(200, [
    'status'  => $status,
    'message' => $result['message'] ?? 'We could not sign you in with Google.',
]);
