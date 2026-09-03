<?php

/**
 * VpnHood! Sign-In — the addon's hooks.
 *
 *  1. ClientAreaHeadOutput renders the Google button on the login, register and
 *     checkout pages and wires it to this addon's api.php.
 *  2. ClientAreaPage holds a freshly-created client on the "how did you hear
 *     about us?" question, when that mode is selected.
 *  3. EmailPreSend drops the "Email Address Verification" mail that WHMCS sends
 *     from inside AddClient, for the one address Google has already verified.
 *
 * This file lives inside the addon rather than in includes/hooks/ on purpose.
 * WHMCS loads modules/addons/<name>/hooks.php ONLY while the addon is
 * activated, which makes deactivating the addon a real kill switch. That
 * matters twice over here: the first hook owns the only way into the site for
 * anyone who signed up with Google, and the second can hold clients on a page.
 *
 * Both fail open. A hook that cannot read its own state renders no button and
 * bars no door; it logs and gets out of the way.
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/SignInGate.php';

use WHMCS\Module\Addon\VpnHoodSignIn\SignInGate;

/**
 * Every name by which the page being rendered might be known.
 *
 * One identifier is not enough. WHMCS routes some client-area pages through
 * index.php, and for those $vars['filename'] is literally "index" while
 * $vars['templatefile'] carries the real identity — the login page reports
 * filename=index, templatefile=login. Others are the other way round: the cart
 * reports filename=cart, templatefile=viewcart. Matching on filename alone
 * silently renders no button on the login page, which is the single most
 * important place for it to appear.
 *
 * So all three known spellings are collected and the configured list is matched
 * against any of them.
 *
 * @return string[]
 */
function vpnhoodsignin_pageIdentifiers(array $vars): array
{
    $names = [
        trim((string) ($vars['filename'] ?? '')),
        trim((string) ($vars['templatefile'] ?? '')),
        strtolower(pathinfo((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PATHINFO_FILENAME)),
    ];

    return array_values(array_unique(array_filter($names, static fn ($n) => $n !== '')));
}

/**
 * Render the sign-in button and its client script.
 *
 * ClientAreaHeadOutput, NOT ClientAreaFooterOutput. WHMCS offers both, but a
 * theme only shows them where it prints {$headoutput} / {$footeroutput}, and
 * the lagom2 theme prints {$footeroutput} solely from its default footer
 * layout — which the login page does not use. {$headoutput} is in the global
 * header.tpl, so it is the one that actually reaches every page we care about.
 *
 * Everything is therefore emitted as a single script and the DOM is built at
 * runtime: nothing may be visible markup this early in the document. The button
 * is rendered through the Google Identity Services API rather than its
 * data-attribute auto-render, which needs no markup in the page at all and lets
 * us place it ourselves.
 *
 * The addon cannot edit a theme it does not own — WHMCS renders its own
 * provider buttons from $linkableProviders, and with the built-in Sign-In
 * Integration switched off that list is empty and the theme draws nothing — so
 * the mount points are a setting and a theme change needs no deploy.
 */
add_hook('ClientAreaHeadOutput', 1, function (array $vars) {
    try {
        $settings = SignInGate::settings();
        if (!$settings['enabled'] || $settings['clientId'] === '') {
            return '';
        }

        // Somebody already signed in has nothing to do with a sign-in button,
        // and the checkout page renders for them too.
        if ((int) ($_SESSION['uid'] ?? 0) > 0) {
            return '';
        }

        if (array_intersect(vpnhoodsignin_pageIdentifiers($vars), $settings['pages']) === []) {
            return '';
        }

        // One nonce per session, minted when the button is drawn. It is handed
        // to google.accounts.id.initialize(), so Google embeds it in the token
        // it signs, and posted alongside that token. api.php checks both: the
        // signed claim binds the token to this session (a captured token cannot
        // be replayed), and the posted copy is a cheap pre-filter that also
        // blocks a stranger signing a visitor into an account of their choosing.
        if (empty($_SESSION['vpnhoodsignin_nonce'])) {
            $_SESSION['vpnhoodsignin_nonce'] = bin2hex(random_bytes(16));
        }

        $config = json_encode([
            'clientId'   => $settings['clientId'],
            'nonce'      => $_SESSION['vpnhoodsignin_nonce'],
            'endpoint'   => '/modules/addons/' . SignInGate::MODULE . '/api.php',
            'placements' => $settings['placements'],
            'locale'     => SignInGate::buttonLocale(),
            // The theme draws these around WHMCS's own provider block and takes
            // them away with it. "or" is WHMCS's own string so it follows the
            // client's language; the other two have no core equivalent to borrow.
            'labels'     => [
                'or'       => (string) ($GLOBALS['_LANG']['or'] ?? 'or'),
                'social'   => 'Use social account (optional)',
                'fillForm' => 'or fill the form below',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return vpnhoodsignin_buttonScript($config);
    } catch (\Throwable $e) {
        SignInGate::log('hook.headOutput', implode(',', vpnhoodsignin_pageIdentifiers($vars)), $e->getMessage());

        return '';
    }
});

/** The whole client script. Kept in one place so the hook stays readable. */
function vpnhoodsignin_buttonScript(string $configJson): string
{
    return <<<HTML
<script>
(function () {
    var cfg = {$configJson};
    var mount, feedback;

    // The theme's own divider, by its own class names, so it inherits the
    // theme's CSS instead of approximating it with inline styles that would
    // drift the next time the theme is restyled.
    function divider(text) {
        var d = document.createElement('div');
        d.className = 'login-divider';
        d.appendChild(document.createElement('span'));
        var mid = document.createElement('span');
        mid.textContent = text;
        d.appendChild(mid);
        d.appendChild(document.createElement('span'));
        return d;
    }

    function caption(text) {
        var d = document.createElement('div');
        d.className = 'text-center m-b-2x';
        var s = document.createElement('span');
        s.textContent = text;
        d.appendChild(s);
        return d;
    }

    function put(node, at, position) {
        if (position === 'after') { at.parentNode.insertBefore(node, at.nextSibling); }
        else if (position === 'prepend') { at.insertBefore(node, at.firstChild); }
        else if (position === 'append') { at.appendChild(node); }
        else { at.parentNode.insertBefore(node, at); }
    }

    function build() {
        // First placement whose selector this theme actually has. Resolved
        // before anything is created, so a page with no match costs nothing.
        var spec = null, at = null;
        for (var i = 0; i < cfg.placements.length; i++) {
            at = document.querySelector(cfg.placements[i].selector);
            if (at && at.parentNode) { spec = cfg.placements[i]; break; }
            at = null;
        }
        if (!spec) {
            // Nothing matched. Fall back to the top of the main content rather
            // than rendering nothing - a button in an odd place still signs
            // people in, and it is visible enough to get reported.
            at = document.querySelector('main, #main-body, .main-content, body');
            if (!at) { return null; }
            spec = {position: 'prepend', style: 'plain'};
        }

        mount = document.createElement('div');
        mount.id = 'vpnhoodsignin-mount';
        if (spec.style === 'plain') { mount.style.margin = '0 0 12px 0'; }

        var buttons = document.createElement('div');
        buttons.className = 'vpnhoodsignin-button';
        buttons.style.display = 'flex';
        buttons.style.justifyContent = 'center';

        feedback = document.createElement('div');
        feedback.className = 'vpnhoodsignin-feedback';
        feedback.style.display = 'none';
        feedback.style.marginTop = '10px';

        // Reproduce what the theme wraps around WHMCS's own provider block, which
        // is gated on that integration being enabled and vanishes with it.
        if (spec.style === 'login') { mount.appendChild(divider(cfg.labels.or)); }
        if (spec.style === 'register') { mount.appendChild(caption(cfg.labels.social)); }

        mount.appendChild(buttons);
        mount.appendChild(feedback);

        if (spec.style === 'register') { mount.appendChild(divider(cfg.labels.fillForm)); }

        put(mount, at, spec.position);

        return buttons;
    }

    function say(text, level) {
        if (!feedback) { return; }
        feedback.className = 'vpnhoodsignin-feedback alert alert-' + (level || 'warning');
        feedback.textContent = text;
        feedback.style.display = '';
    }

    function onCredential(credentialResponse) {
        if (!credentialResponse || !credentialResponse.credential) { return; }
        say('Signing you in…', 'info');

        var body = new URLSearchParams();
        body.append('id_token', credentialResponse.credential);
        body.append('nonce', cfg.nonce);

        fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (data && data.status === 'logged_in' && data.redirectUrl) {
                window.location = data.redirectUrl;
                return;
            }
            say((data && data.message) || 'We could not sign you in with Google.', 'warning');
        }).catch(function () {
            say('We could not reach the sign-in service. Please try again.', 'danger');
        });
    }

    function start() {
        var container = build();
        if (!container) { return; }

        var sdk = document.createElement('script');
        sdk.src = 'https://accounts.google.com/gsi/client';
        sdk.async = true;
        sdk.defer = true;
        sdk.onload = function () {
            if (!window.google || !google.accounts || !google.accounts.id) { return; }
            google.accounts.id.initialize({
                client_id: cfg.clientId,
                nonce: cfg.nonce,
                callback: onCredential,
                ux_mode: 'popup',
                auto_select: false,
                cancel_on_tap_outside: true
            });
            google.accounts.id.renderButton(container, {
                type: 'standard',
                theme: 'outline',
                size: 'large',
                // Match WHMCS built-in wording exactly, so replacing it is invisible.
                text: 'signin_with',
                shape: 'rectangular',
                logo_alignment: 'left',
                // Without this Google labels the button in the language of the
                // visitor's Google account, not the page's. See buttonLocale().
                locale: cfg.locale
            });
        };
        document.head.appendChild(sdk);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
</script>
HTML;
}

/**
 * Hold a Google-created client on the one question the registration form would
 * have asked, when the admin chose to collect it rather than default it.
 *
 * Deliberately narrow — see SignInGate::clientInAskScope. It only ever applies
 * to clients who signed in with Google, have no answer stored, and were created
 * after the addon was switched on.
 */
add_hook('ClientAreaPage', 1, function (array $vars) {
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if ($clientId <= 0) {
        return [];
    }

    try {
        $settings = SignInGate::settings();
        if (!$settings['enabled'] || $settings['fieldMode'] !== SignInGate::MODE_ASK_ONCE) {
            return [];
        }

        if (SignInGate::pageAllowed((string) ($vars['filename'] ?? ''))) {
            return [];
        }

        if (!SignInGate::clientInAskScope($clientId)) {
            return [];
        }

        header('Location: ' . rtrim((string) ($vars['systemurl'] ?? ''), '/')
            . '/index.php?m=' . SignInGate::MODULE);
        exit;
    } catch (\Throwable $e) {
        // A gate that cannot read its own state must not bar the door.
        SignInGate::log('hook.askOnceGate', (string) $clientId, $e->getMessage());

        return [];
    }
});

/**
 * Drop the verification mail for an address Google has already verified.
 *
 * WHMCS sends "Email Address Verification" from inside AddClient whenever
 * EnableEmailVerification is on, and it sends it before AddClient returns — so
 * no amount of marking the address verified afterwards can call it back. The
 * client ends up verified either way (AccountLinker calls
 * setEmailVerificationCompleted immediately after), but without this hook they
 * also get a mail asking them to confirm what they just confirmed, carrying a
 * link that is already spent by the time they read it.
 *
 * The window is opened by AccountLinker around that single AddClient call and
 * closed in a finally, so this can only ever fire for a sign-up this addon is
 * in the middle of. Every other verification mail on the install — a normal
 * registration, an admin resending one, an address change — is untouched.
 *
 * The messagename test is load-bearing, not a tidy-up: AddClient sends the
 * welcome mail from inside the same window, and that mail IS wanted. Dropping
 * on the window alone would swallow it.
 *
 * Fails open, like the other two: if anything here throws, the mail goes out.
 * An unwanted mail is a nuisance; a swallowed one can lock somebody out.
 */
add_hook('EmailPreSend', 1, function (array $vars) {
    try {
        if (($vars['messagename'] ?? '') !== 'Email Address Verification') {
            return [];
        }

        if (!SignInGate::isSuppressingVerificationEmail()) {
            return [];
        }

        return ['abortsend' => true];
    } catch (\Throwable $e) {
        SignInGate::log('hook.verificationMail', (string) ($vars['messagename'] ?? ''), $e->getMessage());

        return [];
    }
});

/**
 * Daily refresh of the VpnHood update check.
 *
 * The check itself lives in modules/widgets/vpnhoodupdates.php, which every
 * VpnHood package ships at that same path; this is only its clock. The daily cron
 * is the one place where waiting a few seconds on github.com costs nobody
 * anything — the dashboard widget then renders what this left behind and never
 * makes a request of its own.
 *
 * Every installed VpnHood addon registers this same hook, on purpose: whichever
 * packages an install has, something winds the clock. The cache TTL makes every
 * call after the first one that day a no-op, so the duplicates cost nothing.
 *
 * Best-effort by design: an install with no outbound access to github.com, or a
 * GitHub outage, must cost nothing but a "check failed" line. The daily cron is
 * never allowed to fail over a version check.
 */
add_hook('DailyCronJob', 1, function () {
    try {
        $check = ROOTDIR . '/modules/widgets/vpnhoodupdates.php';
        if (!is_readable($check)) {
            return;
        }
        require_once $check;
        VpnHoodUpdateCheck::refresh();
    } catch (\Throwable $e) {
        logModuleCall('vpnhood', 'hook.update-check', [], $e->getMessage(), '');
    }
});
