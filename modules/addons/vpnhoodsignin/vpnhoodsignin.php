<?php

/**
 * VpnHood! Sign-In
 *
 * Makes "Sign in with Google" behave the way every other OAuth flow does:
 * an address WHMCS has never seen becomes an account and is signed in, and an
 * address it already knows is linked and signed in. WHMCS's own Sign-In
 * Integration does neither — it stops at "Link Initiated! Please complete sign
 * in to associate this service with your existing account", which asks a
 * brand-new visitor to sign in with a password they have never had.
 *
 * This addon owns the whole flow: it renders its own Google button, verifies
 * the ID token itself, creates or links the account, and mints the session.
 * WHMCS's built-in Sign-In Integration should be switched OFF alongside it.
 *
 * Standalone by design: it depends on no other VpnHood module, stores no
 * duplicate state (the link is WHMCS's own tblauthn_account_links row), owns no
 * tables, and everything it does stops the moment the addon is deactivated —
 * WHMCS only loads an active addon's hooks.php.
 *
 * @see hooks.php               the button and the ask-once gate
 * @see api.php                 the endpoint the button posts to
 * @see lib/GoogleToken.php     the trust boundary: what makes a token believable
 * @see lib/AccountLinker.php   create / link / sign in
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodSignIn\GoogleToken;
use WHMCS\Module\Addon\VpnHoodSignIn\SignInGate;

require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/GoogleToken.php';
require_once __DIR__ . '/lib/SignInGate.php';

/**
 * Addon configuration / metadata.
 */
function vpnhoodsignin_config(): array
{
    return [
        'name'        => 'VpnHood! Sign-In',
        'description' => 'Lets "Sign in with Google" register new clients and sign in existing ones, instead of dead-ending at "Link Initiated". Replaces the built-in Sign-In Integration.',
        'version'     => '1.1.3',
        'author'      => 'VpnHood',
        'fields'      => [
            'Enabled' => [
                'FriendlyName' => 'Enable Google Sign-In',
                'Type'         => 'yesno',
                'Description'  => 'Render the Google button and accept sign-ins. Turn this off to leave the addon installed but inert — the button disappears and the endpoint answers 404.',
                'Default'      => 'no',
            ],
            'GoogleClientId' => [
                'FriendlyName' => 'Google Client ID',
                'Type'         => 'text',
                'Size'         => '80',
                'Description'  => 'The OAuth 2.0 Client ID from Google Cloud Console, ending in .apps.googleusercontent.com. Nothing works without it: it is what proves a token was minted for THIS site and not some other Google app. No client secret is needed — this flow never exchanges a code.',
                'Default'      => '',
            ],
            'ButtonPages' => [
                'FriendlyName' => 'Show Button On',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Comma-separated page identifiers that get the button. Matched against the page filename AND its template name, because WHMCS routes some pages through index.php (the login page reports filename <code>index</code>, template <code>login</code>). Defaults to <code>login,register,cart,clientregister,viewcart</code>.',
                'Default'      => 'login,register,cart,clientregister,viewcart',
            ],
            'ButtonPlacements' => [
                'FriendlyName' => 'Button Placement',
                'Type'         => 'textarea',
                'Rows'         => '5',
                'Cols'         => '60',
                'Description'  => 'One <code>position|selector|style</code> per line, tried in order until one matches. '
                    . '<strong>position</strong>: before, after, prepend, append (relative to the element the selector names). '
                    . '<strong>style</strong>: <code>login</code> draws an “or” divider above the button, '
                    . '<code>register</code> draws a label above and an “or fill the form below” divider under it, '
                    . '<code>plain</code> draws neither — these reproduce what the theme itself puts around WHMCS\'s '
                    . 'built-in provider block, which disappears with it. A line with no pipes is read as a bare '
                    . 'selector at <code>before</code>. Leave blank for the built-in list, which matches the stock lagom2 '
                    . 'login and register pages.',
                'Default'      => "after|form.login-form|login\nprepend|#containerNewUserSignup|register\nprepend|#frmCheckout|register\nbefore|form.loginForm|login",
            ],
            'ButtonLanguage' => [
                'FriendlyName' => 'Button Language',
                'Type'         => 'text',
                'Size'         => '6',
                'Description'  => 'Language code Google draws the button in, e.g. <code>en</code>. Leave blank to '
                    . 'follow the client area\'s own language. This is worth setting explicitly: Google ignores the '
                    . 'page and the browser, and defaults to the language of whichever Google account the visitor is '
                    . 'signed into — so an English page can show a button in another language entirely. WHMCS\'s '
                    . 'built-in integration always sends <code>en</code>.',
                'Default'      => '',
            ],
            'ExistingAccountAction' => [
                'FriendlyName' => 'When The Email Already Exists',
                'Type'         => 'dropdown',
                'Options'      => 'Link and notify,Link silently,Refuse',
                'Description'  => 'What to do when Google returns an address that already has a WHMCS account. Linking is safe because Google has verified the address — the same proof a password reset needs — and "and notify" tells the owner it happened.',
                'Default'      => 'Link and notify',
            ],
            'CustomFieldName' => [
                'FriendlyName' => 'Custom Field To Fill',
                'Type'         => 'text',
                'Size'         => '50',
                'Description'  => 'The exact name of the required client custom field that the registration form would have asked for. Matched by name at runtime, never by id — the id differs between installs. Leave blank to ignore custom fields entirely.',
                'Default'      => 'How did you hear about us?',
            ],
            'CustomFieldMode' => [
                'FriendlyName' => 'How To Fill It',
                'Type'         => 'dropdown',
                'Options'      => 'Use default value,Ask once after signup,Leave empty',
                'Description'  => '<strong>Use default value</strong> writes the value below at signup (zero friction). <strong>Ask once after signup</strong> holds the new client on a one-question page until they answer (keeps the data honest). <strong>Leave empty</strong> does neither.',
                'Default'      => 'Use default value',
            ],
            'CustomFieldDefault' => [
                'FriendlyName' => 'Default Value',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Used by "Use default value". Must be one of the field\'s own dropdown options, or it writes a value nobody can ever select again.',
                'Default'      => 'Other',
            ],
            'DefaultCountry' => [
                'FriendlyName' => 'Country Override',
                'Type'         => 'text',
                'Size'         => '4',
                'Description'  => 'Two-letter ISO code for new clients. Leave blank to use the visitor\'s country from Cloudflare, falling back to the WHMCS default country.',
                'Default'      => '',
            ],
            'CutoffDate' => [
                'FriendlyName' => 'New-Client Cutoff (YYYY-MM-DD)',
                'Type'         => 'text',
                'Size'         => '12',
                'Description'  => 'Stamped with today\'s date at activation. Only clients created on or after it can be held by "Ask once after signup", so switching the addon on never retroactively bails up your existing client base.',
                'Default'      => '',
            ],
        ],
    ];
}

/**
 * Stamp the cutoff at activation, so enabling the ask-once mode can never
 * retroactively hold existing clients on a question they were never asked.
 */
function vpnhoodsignin_activate(): array
{
    try {
        $today = vpnhoodsignin_stampCutoff();

        return [
            'status'      => 'success',
            'description' => 'Cutoff set to ' . $today . '. Add your Google Client ID and set '
                . 'Enable Google Sign-In to Yes, then switch OFF the built-in integration at '
                . 'System Settings -> Sign-In Integrations.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Could not stamp the cutoff date: ' . $e->getMessage(),
        ];
    }
}

/**
 * Write today's date into the CutoffDate setting, and return it.
 *
 * Called from _activate() and again from the admin page, because WHMCS may seed
 * an addon's default setting rows AFTER _activate() has run, which would blank
 * what activation just stamped. Only re-stamping an empty value keeps this from
 * clobbering a date an admin typed deliberately.
 */
function vpnhoodsignin_stampCutoff(): string
{
    $today = date('Y-m-d');

    $exists = Capsule::table('tbladdonmodules')
        ->where('module', SignInGate::MODULE)
        ->where('setting', 'CutoffDate')
        ->exists();

    if ($exists) {
        Capsule::table('tbladdonmodules')
            ->where('module', SignInGate::MODULE)
            ->where('setting', 'CutoffDate')
            ->update(['value' => $today]);
    } else {
        Capsule::table('tbladdonmodules')->insert([
            'module'  => SignInGate::MODULE,
            'setting' => 'CutoffDate',
            'value'   => $today,
        ]);
    }

    return $today;
}

function vpnhoodsignin_deactivate(): array
{
    // Nothing to tear down: no tables, no private state. The account links stay
    // in WHMCS's own tblauthn_account_links, where WHMCS's built-in Sign-In
    // Integration will find them if you switch it back on.
    return [
        'status'      => 'success',
        'description' => 'Google sign-in is no longer offered. Existing account links were left untouched.',
    ];
}

/**
 * Admin page: whether this is actually working, and what would stop it.
 *
 * Every check here is one that silently produces "the button does nothing",
 * which is the failure mode an admin cannot diagnose from the front end.
 */
function vpnhoodsignin_output($vars): void
{
    $settings = SignInGate::settings();

    if ($settings['cutoff'] === '') {
        try {
            $settings['cutoff'] = vpnhoodsignin_stampCutoff();
        } catch (\Throwable $e) {
            // non-fatal; the warning below still tells the admin what to do
        }
    }

    echo '<h3>Status</h3>';

    if ($settings['clientId'] === '') {
        echo '<div class="alert alert-danger"><strong>No Google Client ID.</strong> '
           . 'The button will not render and every sign-in would be refused. Paste the OAuth 2.0 '
           . 'Client ID from Google Cloud Console into the settings above.</div>';
    } elseif (!str_ends_with($settings['clientId'], '.apps.googleusercontent.com')) {
        echo '<div class="alert alert-warning"><strong>That Client ID looks wrong.</strong> '
           . 'Google client IDs end in <code>.apps.googleusercontent.com</code>.</div>';
    }

    if (!$settings['enabled']) {
        echo '<div class="alert alert-info">Google sign-in is <strong>off</strong>. '
           . 'The addon is installed but renders nothing and answers 404.</div>';
    }

    $builtIn = vpnhoodsignin_builtInIntegrationState();
    if ($builtIn === true) {
        echo '<div class="alert alert-warning"><strong>The built-in Sign-In Integration is still enabled.</strong> '
           . 'Your theme will draw WHMCS\'s own Google button as well as this one, and WHMCS\'s still '
           . 'dead-ends at "Link Initiated". Turn it off at <em>System Settings &rarr; '
           . 'Sign-In Integrations</em>.</div>';
    }

    echo '<table class="table table-condensed" style="width:auto;"><tbody>'
       . '<tr><td>Google sign-in</td><td><strong>' . ($settings['enabled'] ? 'ON' : 'off') . '</strong></td></tr>'
       . '<tr><td>Client ID</td><td><code>' . htmlspecialchars(
           $settings['clientId'] !== '' ? $settings['clientId'] : '(not set)', ENT_QUOTES) . '</code></td></tr>'
       . '<tr><td>Built-in Sign-In Integration</td><td>' . (
           $builtIn === null ? '<em>could not be read</em>' : ($builtIn ? '<strong>enabled</strong>' : 'disabled')
       ) . '</td></tr>'
       . '<tr><td>Button pages</td><td><code>' . htmlspecialchars(implode(', ', $settings['pages']), ENT_QUOTES) . '</code></td></tr>'
       . '<tr><td>Button placement</td><td>' . vpnhoodsignin_placementSummary($settings) . '</td></tr>'
       . '<tr><td>Button language</td><td><code>' . htmlspecialchars(SignInGate::buttonLocale(), ENT_QUOTES) . '</code>'
       . ($settings['buttonLanguage'] === '' ? ' <small>(following the client area)</small>' : ' <small>(set explicitly)</small>')
       . '</td></tr>'
       . '<tr><td>Existing email</td><td>' . htmlspecialchars($settings['existingAction'], ENT_QUOTES) . '</td></tr>'
       . '<tr><td>New-client cutoff</td><td><code>' . htmlspecialchars(
           $settings['cutoff'] !== '' ? $settings['cutoff'] : '(not set)', ENT_QUOTES) . '</code></td></tr>'
       . '<tr><td>Google-linked accounts</td><td><strong>' . vpnhoodsignin_linkCount() . '</strong></td></tr>'
       . '</tbody></table>';

    echo '<h3>Custom field</h3>';
    vpnhoodsignin_outputCustomFieldStatus($settings);

    echo '<h3>Two-factor accounts</h3>'
       . '<p>A client with WHMCS two-step verification enabled is <strong>never</strong> signed in '
       . 'through Google. Signing in this way lands straight in the client area, which would quietly '
       . 'cancel a second factor they deliberately turned on, so they are asked for their password '
       . 'instead and WHMCS runs its own challenge.</p>';

    echo '<h3>If something goes wrong</h3>'
       . '<p>Set <em>Enable Google Sign-In</em> to No, or deactivate the addon — that stops the hooks '
       . 'loading at all and restores the stock login page. Every rejected sign-in is recorded in '
       . '<em>Utilities &rarr; Logs &rarr; Module Log</em> under <code>' . SignInGate::MODULE . '</code>, '
       . 'with the real reason (the visitor only ever sees a generic message).</p>';
}

/**
 * The placement list, as the page script will read it.
 *
 * Worth showing because a placement that matches nothing is invisible from the
 * admin side and looks identical to “the button is broken” from the front.
 */
function vpnhoodsignin_placementSummary(array $settings): string
{
    if ($settings['placements'] === []) {
        return '<span class="text-danger"><strong>none</strong> — every line was malformed, '
             . 'so the button falls back to the top of the page</span>';
    }

    $lines = [];
    foreach ($settings['placements'] as $p) {
        $lines[] = '<code>' . htmlspecialchars($p['position'] . ' ' . $p['selector'], ENT_QUOTES) . '</code>'
            . ' <small>(' . htmlspecialchars($p['style'], ENT_QUOTES) . ')</small>';
    }

    return implode('<br>', $lines);
}

/** The custom-field half of the admin page — the fiddliest thing to get right. */
function vpnhoodsignin_outputCustomFieldStatus(array $settings): void
{
    if ($settings['fieldName'] === '') {
        echo '<div class="alert alert-info">No custom field configured; nothing is written or asked.</div>';

        return;
    }

    $fieldId = SignInGate::customFieldId();
    if ($fieldId <= 0) {
        echo '<div class="alert alert-danger"><strong>No client custom field named '
           . '&ldquo;' . htmlspecialchars($settings['fieldName'], ENT_QUOTES) . '&rdquo;.</strong> '
           . 'Check the name matches exactly, at <em>System Settings &rarr; Custom Client Fields</em>.</div>';

        return;
    }

    $options = SignInGate::customFieldOptions();
    echo '<table class="table table-condensed" style="width:auto;"><tbody>'
       . '<tr><td>Field</td><td><code>#' . $fieldId . '</code> '
       . htmlspecialchars($settings['fieldName'], ENT_QUOTES) . '</td></tr>'
       . '<tr><td>Mode</td><td>' . htmlspecialchars($settings['fieldMode'], ENT_QUOTES) . '</td></tr>'
       . '<tr><td>Options</td><td>' . htmlspecialchars(implode(' | ', $options), ENT_QUOTES) . '</td></tr>'
       . '</tbody></table>';

    if ($settings['fieldMode'] === SignInGate::MODE_DEFAULT_VALUE && !SignInGate::defaultValueIsValid()) {
        echo '<div class="alert alert-danger"><strong>The default value is not one of the field\'s options.</strong> '
           . 'Writing <code>' . htmlspecialchars($settings['fieldDefault'], ENT_QUOTES) . '</code> would store '
           . 'something the client can never see or re-select, so nothing will be written at all. '
           . 'Use one of the options listed above (or add it to the field).</div>';
    }

    if ($settings['fieldMode'] === SignInGate::MODE_ASK_ONCE) {
        echo '<p>Clients currently held on the question: <strong>'
           . vpnhoodsignin_pendingAnswerCount($settings) . '</strong></p>';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
            echo '<div class="alert alert-danger">The cutoff date is missing or malformed, so nobody '
               . 'can be identified as new and <strong>nobody is asked</strong>.</div>';
        }
    }
}

/**
 * Whether WHMCS's own google_signin integration is switched on.
 *
 * tblauthn_config values are encrypted, and NOT with the scheme the
 * DecryptPassword API uses — feeding them to it returns binary noise rather
 * than failing, which is precisely the trap this function has to avoid. So the
 * result is only believed when it decrypts to something recognisable as a
 * boolean; anything else reports null and the admin page prints "could not be
 * read". An honest unknown beats a confident wrong answer on a warning whose
 * whole job is to explain why two Google buttons are showing.
 */
function vpnhoodsignin_builtInIntegrationState(): ?bool
{
    try {
        $stored = Capsule::table('tblauthn_config')
            ->where('provider', GoogleToken::PROVIDER)
            ->where('setting', 'Enabled')
            ->value('value');

        if ($stored === null) {
            return false; // never configured, so certainly not enabled
        }

        $decrypted = localAPI('DecryptPassword', ['password2' => $stored]);
        $value = strtolower(trim((string) ($decrypted['password'] ?? '')));

        if (in_array($value, ['1', 'on', 'yes', 'true'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'off', 'no', 'false'], true)) {
            return false;
        }

        return null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** How many accounts can currently sign in with Google. */
function vpnhoodsignin_linkCount(): int
{
    try {
        return (int) Capsule::table('tblauthn_account_links')
            ->where('provider', GoogleToken::PROVIDER)
            ->count();
    } catch (\Throwable $e) {
        return 0;
    }
}

/** How many in-scope clients still owe an answer to the custom field. */
function vpnhoodsignin_pendingAnswerCount(array $settings): int
{
    $fieldId = SignInGate::customFieldId();
    if ($fieldId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
        return 0;
    }

    try {
        return (int) Capsule::table('tblclients')
            ->where('tblclients.datecreated', '>=', $settings['cutoff'])
            ->whereExists(function ($query) {
                $query->select(Capsule::raw(1))
                    ->from('tblauthn_account_links')
                    ->join('tblusers_clients', 'tblusers_clients.auth_user_id', '=', 'tblauthn_account_links.user_id')
                    ->whereRaw('tblusers_clients.client_id = tblclients.id')
                    ->where('tblauthn_account_links.provider', GoogleToken::PROVIDER);
            })
            ->whereNotExists(function ($query) use ($fieldId) {
                $query->select(Capsule::raw(1))
                    ->from('tblcustomfieldsvalues')
                    ->whereRaw('tblcustomfieldsvalues.relid = tblclients.id')
                    ->where('tblcustomfieldsvalues.fieldid', $fieldId)
                    ->whereRaw("TRIM(COALESCE(tblcustomfieldsvalues.value, '')) NOT IN ('', ?)", [SignInGate::UNANSWERED]);
            })
            ->count();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * The one-question page for "Ask once after signup".
 *
 * The only page this addon serves, and the only place its gate sends people.
 * It asks exactly what the registration form would have asked and nothing else.
 */
function vpnhoodsignin_clientarea($vars): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    $settings = SignInGate::settings();
    $options = SignInGate::customFieldOptions();

    // The sentinel is a prompt, not an answer; never offer it as a choice.
    $options = array_values(array_filter($options, static fn ($o) => $o !== SignInGate::UNANSWERED));

    $saved = false;
    $error = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $clientId > 0) {
        $choice = trim((string) ($_POST['answer'] ?? ''));
        if ($choice === '' || !in_array($choice, $options, true)) {
            $error = 'Please choose one of the options.';
        } else {
            $saved = vpnhoodsignin_saveAnswer($clientId, $choice);
            if (!$saved) {
                $error = 'We could not save that. Please try again.';
            }
        }
    }

    return [
        'pagetitle'    => 'One quick question',
        'breadcrumb'   => ['index.php?m=' . SignInGate::MODULE => 'One quick question'],
        'templatefile' => 'how-did-you-hear',
        'requirelogin' => true,
        'vars'         => [
            'question' => $settings['fieldName'],
            'options'  => $options,
            'saved'    => $saved,
            'error'    => $error,
            'module'   => SignInGate::MODULE,
        ],
    ];
}

/**
 * Store the answer through WHMCS rather than writing tblcustomfieldsvalues
 * directly, so any validation, hook or side effect WHMCS attaches to a client
 * update still happens.
 */
function vpnhoodsignin_saveAnswer(int $clientId, string $choice): bool
{
    $fieldId = SignInGate::customFieldId();
    if ($fieldId <= 0) {
        return false;
    }

    try {
        $result = localAPI('UpdateClient', [
            'clientid'       => $clientId,
            'customfields'   => base64_encode(serialize([$fieldId => $choice])),
            'skipvalidation' => true,
        ]);

        if (($result['result'] ?? '') === 'success') {
            return true;
        }
        SignInGate::log('saveAnswer.failed', ['clientId' => $clientId], $result);
    } catch (\Throwable $e) {
        SignInGate::log('saveAnswer.threw', ['clientId' => $clientId], $e->getMessage());
    }

    return false;
}
