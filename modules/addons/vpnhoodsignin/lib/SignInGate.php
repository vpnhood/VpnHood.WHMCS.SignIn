<?php

namespace WHMCS\Module\Addon\VpnHoodSignIn;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Shared state: settings, the "how did you hear about us?" custom field, the
 * country fallback chain, and the scope of the ask-once gate.
 *
 * Lives in lib/ because three entry points need it and they load independently:
 * hooks.php (loaded by WHMCS only while the addon is active), api.php (a
 * standalone endpoint), and vpnhoodsignin.php (the admin and client-area
 * pages). None of them can rely on another having been included.
 *
 * Nothing here keeps duplicate state. The account link is WHMCS's own
 * tblauthn_account_links row, the answer to the custom field is WHMCS's own
 * tblcustomfieldsvalues row, and this module never keeps a private copy of
 * either — the same discipline vpnhoodverify applies to email_verified_at.
 */
class SignInGate
{
    public const MODULE = 'vpnhoodsignin';

    public const MODE_DEFAULT_VALUE = 'Use default value';
    public const MODE_ASK_ONCE      = 'Ask once after signup';
    public const MODE_LEAVE_EMPTY   = 'Leave empty';

    public const EXISTING_LINK_NOTIFY = 'Link and notify';
    public const EXISTING_LINK_SILENT = 'Link silently';
    public const EXISTING_REFUSE      = 'Refuse';

    /**
     * The sentinel first option of a WHMCS dropdown custom field. Storing it
     * would be indistinguishable from never having answered.
     */
    public const UNANSWERED = '-- Please choose --';

    /** Pages that stay reachable while the ask-once gate holds someone. */
    private const ALWAYS_ALLOWED = ['logout', 'verifyemail', 'password-reset', 'pwreset'];

    /**
     * Where the sign-in button is mounted, tried in order until one matches.
     *
     * One "position|selector|style" per line. The defaults put the button
     * exactly where the theme puts WHMCS's own provider block, which is not the
     * same place on both pages and is not reachable by "insert before X" alone:
     *
     *   login    the block sits BETWEEN the login form and the footer, under an
     *            "or" divider — so: after the form.
     *   register the block sits FIRST INSIDE the signup section, under a label
     *            and above an "or fill the form below" divider — so: prepend.
     *
     * Both of those are inside the theme's `{if $linkableProviders}`, so they
     * exist only while WHMCS's built-in Sign-In Integration is on. This addon
     * replaces that integration, so it draws its own copy of the surrounding
     * markup — see the `style` column and vpnhoodsignin_buttonScript().
     *
     * A line with no pipe is read as a bare selector at position `before`,
     * which is the format this setting used before styles existed.
     */
    private const DEFAULT_PLACEMENTS = "after|form.login-form|login\n"
        . "prepend|#containerNewUserSignup|register\n"
        . "prepend|#frmCheckout|register\n"
        . "before|form.loginForm|login";

    /** Where a placement may put the button, relative to the element it names. */
    private const POSITIONS = ['before', 'after', 'prepend', 'append'];

    /** Which surrounding markup to draw. See vpnhoodsignin_buttonScript(). */
    private const STYLES = ['plain', 'login', 'register'];

    /**
     * Addon settings as WHMCS stored them in tbladdonmodules.
     *
     * WHMCS writes a 'yesno' field as 'on' (or nothing at all) rather than
     * 'yes', so both spellings are accepted — the same reason VerifyGate does.
     *
     * @return array{enabled:bool, clientId:string, pages:string[], selectors:string[],
     *               existingAction:string, fieldName:string, fieldMode:string,
     *               fieldDefault:string, country:string, cutoff:string}
     */
    public static function settings(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $rows = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->pluck('value', 'setting');

        $placements = trim((string) ($rows['ButtonPlacements'] ?? ''));
        if ($placements === '') {
            // An install configured before placements existed still has the old
            // key; its bare selectors parse as "before|<selector>|plain".
            $placements = trim((string) ($rows['ButtonSelectors'] ?? ''));
        }
        if ($placements === '') {
            $placements = self::DEFAULT_PLACEMENTS;
        }

        $pages = trim((string) ($rows['ButtonPages'] ?? ''));
        if ($pages === '') {
            $pages = 'login,register,cart,clientregister,viewcart';
        }

        return $cached = [
            'enabled'        => in_array((string) ($rows['Enabled'] ?? ''), ['on', 'yes', '1'], true),
            'clientId'       => trim((string) ($rows['GoogleClientId'] ?? '')),
            'pages'          => self::splitList($pages, ','),
            'placements'     => self::parsePlacements($placements),
            'existingAction' => (string) ($rows['ExistingAccountAction'] ?? self::EXISTING_LINK_NOTIFY),
            'fieldName'      => trim((string) ($rows['CustomFieldName'] ?? '')),
            'fieldMode'      => (string) ($rows['CustomFieldMode'] ?? self::MODE_LEAVE_EMPTY),
            'fieldDefault'   => trim((string) ($rows['CustomFieldDefault'] ?? '')),
            'country'        => strtoupper(trim((string) ($rows['DefaultCountry'] ?? ''))),
            'cutoff'         => trim((string) ($rows['CutoffDate'] ?? '')),
            'buttonLanguage' => trim((string) ($rows['ButtonLanguage'] ?? '')),
        ];
    }

    /**
     * The language Google should draw the button in.
     *
     * Google Identity Services does NOT follow the page or the browser. Left to
     * itself it renders the button in the language of whatever Google account
     * the visitor is signed into, so an English client area shows a Persian
     * button to a Persian Google user. WHMCS's own integration sidesteps this by
     * always sending hl=en; we follow the client area's language instead, which
     * is what the rest of the page is already in.
     *
     * WHMCS language files carry $_LANG['locale'] ("en_001", "fa_IR"); Google
     * wants the language part of that. Anything unrecognisable falls back to
     * English rather than being passed through — an invalid hl makes Google
     * ignore the parameter, which puts us back to the account-language default.
     */
    public static function buttonLocale(): string
    {
        $configured = self::settings()['buttonLanguage'];
        if ($configured !== '') {
            return $configured;
        }

        $parts = explode('_', (string) ($GLOBALS['_LANG']['locale'] ?? ''));
        $code = strtolower(trim($parts[0]));

        return preg_match('/^[a-z]{2,3}$/', $code) === 1 ? $code : 'en';
    }

    /**
     * Parse the placement list into something the page script can act on.
     *
     * A malformed line is skipped rather than guessed at: a placement with an
     * unrecognised position would otherwise silently fall back to `before` and
     * put the button somewhere the admin did not ask for, which is harder to
     * spot than a button that simply is not there. An unrecognised STYLE is
     * only decoration, so that one degrades to `plain` instead.
     *
     * @return array<int, array{position:string, selector:string, style:string}>
     */
    private static function parsePlacements(string $value): array
    {
        $placements = [];

        foreach (self::splitList($value, "\n") as $line) {
            $parts = array_map('trim', explode('|', $line));

            // No pipe at all: the pre-placement format, a bare CSS selector.
            if (count($parts) === 1) {
                $parts = ['before', $parts[0], 'plain'];
            }

            $position = strtolower($parts[0] ?? '');
            $selector = $parts[1] ?? '';
            $style    = strtolower($parts[2] ?? '');

            if ($selector === '' || !in_array($position, self::POSITIONS, true)) {
                continue;
            }

            $placements[] = [
                'position' => $position,
                'selector' => $selector,
                'style'    => in_array($style, self::STYLES, true) ? $style : 'plain',
            ];
        }

        return $placements;
    }

    /** @return string[] non-empty trimmed pieces */
    private static function splitList(string $value, string $separator): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, $value)), static fn ($v) => $v !== ''));
    }

    /** Forget the cached settings — only needed after the admin page writes one. */
    public static function flushCache(): void
    {
        // settings() caches in a static local, so re-reading needs a fresh
        // process; this exists for the admin page, which re-reads explicitly.
    }

    // -------------------------------------------- the verification-mail window

    /**
     * True only while AccountLinker is inside the AddClient call that creates a
     * client from a Google-verified address. See suppressVerificationEmail().
     */
    private static bool $creatingVerifiedClient = false;

    /**
     * Open or close the window in which WHMCS's "Email Address Verification"
     * mail is dropped by the EmailPreSend hook in hooks.php.
     *
     * WHMCS sends that mail from inside AddClient, before AddClient has even
     * returned the client id — so there is no moment at which the addon could
     * mark the address verified early enough to stop it. It calls
     * setEmailVerificationCompleted() immediately afterwards, which leaves the
     * account correctly verified but the mail already gone: the client is asked
     * to confirm an address Google confirmed seconds earlier, and the link they
     * are sent does nothing when they click it.
     *
     * A process-wide flag rather than anything persisted, because the whole
     * window is a few lines of one request: set immediately before AddClient,
     * cleared in a finally immediately after. A fatal cannot leave it stuck on
     * — the process ends with it.
     *
     * The window is NOT empty of other mail. AddClient sends the welcome mail
     * too, from inside the same call, so that one is also in flight while this
     * is true. What keeps it safe is the hook's messagename test, not the width
     * of the window: the hook drops "Email Address Verification" and nothing
     * else. Widening this flag's meaning to "drop mail" would take the welcome
     * mail with it.
     *
     * Deliberately NOT keyed on the address: at the moment the mail is sent the
     * user row does not exist yet, and EmailPreSend reports the user id (which
     * is not the client id) for a mail whose recipient we could not look up
     * anyway. The narrow window is what makes this safe, not the recipient.
     */
    public static function suppressVerificationEmail(bool $on): void
    {
        self::$creatingVerifiedClient = $on;
    }

    /** Whether a verification mail sent right now would be a pointless one. */
    public static function isSuppressingVerificationEmail(): bool
    {
        return self::$creatingVerifiedClient;
    }

    // ------------------------------------------------------------ custom field

    /**
     * The id of the configured client custom field, or 0 when it does not exist.
     *
     * Resolved by NAME at runtime and never hardcoded: the id differs per
     * install (43 on our dev WHMCS) and a stale constant silently disables the
     * whole feature. WHMCS also appends "|validation" suffixes to fieldname,
     * so match on the part before the pipe.
     */
    public static function customFieldId(): int
    {
        static $cache = [];
        $name = self::settings()['fieldName'];
        if ($name === '') {
            return 0;
        }
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        try {
            $id = Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->whereRaw("SUBSTRING_INDEX(fieldname, '|', 1) = ?", [$name])
                ->value('id');

            return $cache[$name] = (int) ($id ?? 0);
        } catch (\Throwable $e) {
            return $cache[$name] = 0;
        }
    }

    /**
     * The dropdown options of the configured field, sentinel included.
     *
     * @return string[]
     */
    public static function customFieldOptions(): array
    {
        $id = self::customFieldId();
        if ($id <= 0) {
            return [];
        }
        try {
            $options = (string) Capsule::table('tblcustomfields')->where('id', $id)->value('fieldoptions');
            return self::splitList($options, ',');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The client's stored answer, or '' when they have not given one. */
    public static function customFieldValue(int $clientId): string
    {
        $id = self::customFieldId();
        if ($id <= 0 || $clientId <= 0) {
            return '';
        }
        try {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $id)->where('relid', $clientId)->value('value');
            $value = trim((string) ($value ?? ''));

            return $value === self::UNANSWERED ? '' : $value;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Whether the configured default is actually one of the field's options.
     *
     * A dropdown custom field only renders values from its own option list, so
     * a default that is not in it writes a value the client can never see or
     * re-select, and reports would show a category that does not exist.
     */
    public static function defaultValueIsValid(): bool
    {
        $default = self::settings()['fieldDefault'];
        if ($default === '' || $default === self::UNANSWERED) {
            return false;
        }
        $options = self::customFieldOptions();
        if ($options === []) {
            return false;
        }

        return in_array($default, $options, true);
    }

    // ------------------------------------------------------------------- gate

    /**
     * Whether the ask-once gate applies to this client.
     *
     * Three conditions, all required: the client signed in with Google (so we
     * never interrogate someone who registered normally and legitimately
     * skipped an optional field), they have no answer yet, and they were
     * created on or after the cutoff stamped at activation.
     *
     * The cutoff exists for the same reason vpnhoodverify has one: switching
     * this addon on must not retroactively bail up the existing client base
     * over a field they were never asked in a flow they never used. A missing
     * or malformed cutoff gates nobody — fail open, like everything else here.
     */
    public static function clientInAskScope(int $clientId): bool
    {
        $settings = self::settings();
        if ($settings['fieldMode'] !== self::MODE_ASK_ONCE || $clientId <= 0) {
            return false;
        }
        if (self::customFieldId() <= 0) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $settings['cutoff'])) {
            return false;
        }
        if (self::customFieldValue($clientId) !== '') {
            return false;
        }

        try {
            $isNew = Capsule::table('tblclients')
                ->where('id', $clientId)
                ->where('datecreated', '>=', $settings['cutoff'])
                ->exists();
            if (!$isNew) {
                return false;
            }

            return self::hasGoogleLink($clientId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether any user owning this client has a Google link. */
    public static function hasGoogleLink(int $clientId): bool
    {
        try {
            return Capsule::table('tblauthn_account_links')
                ->where('provider', GoogleToken::PROVIDER)
                ->whereIn('user_id', function ($query) use ($clientId) {
                    $query->select('auth_user_id')->from('tblusers_clients')->where('client_id', $clientId);
                })
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether the page being requested stays reachable while gated. */
    public static function pageAllowed(string $filename): bool
    {
        if (in_array($filename, self::ALWAYS_ALLOWED, true)) {
            return true;
        }
        if ((string) ($_GET['m'] ?? '') === self::MODULE) {
            return true;
        }

        $path = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return str_contains($path, '/user/verify') || str_contains($path, 'verifyemail');
    }

    // ---------------------------------------------------------------- country

    /**
     * The country to register a new client with.
     *
     * Google tells us nothing about location, and WHMCS requires a country even
     * with skipvalidation, so this is a best guess the client can correct on
     * their profile. Cloudflare sits in front of the site and offers the
     * visitor's country for free; XX (unknown) and T1 (Tor) are meaningless as
     * addresses and fall through.
     */
    public static function resolveCountry(): string
    {
        $configured = self::settings()['country'];
        if (preg_match('/^[A-Z]{2}$/', $configured)) {
            return $configured;
        }

        $fromCloudflare = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $fromCloudflare) && !in_array($fromCloudflare, ['XX', 'T1'], true)) {
            return $fromCloudflare;
        }

        try {
            $default = strtoupper(trim((string) Capsule::table('tblconfiguration')
                ->where('setting', 'DefaultCountry')->value('value')));
            if (preg_match('/^[A-Z]{2}$/', $default)) {
                return $default;
            }
        } catch (\Throwable $e) {
            // fall through to the hard default
        }

        return 'US';
    }

    /** The visitor's IP, for AddClient's clientip. */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
            $ip = trim((string) ($_SERVER[$key] ?? ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    /** Log a diagnostic without ever letting logging break the request. */
    public static function log(string $action, $request, $response): void
    {
        try {
            logModuleCall(
                self::MODULE,
                $action,
                is_string($request) ? $request : json_encode($request),
                is_string($response) ? $response : json_encode($response),
                ''
            );
        } catch (\Throwable $e) {
            // diagnostics must never be load-bearing
        }
    }
}
