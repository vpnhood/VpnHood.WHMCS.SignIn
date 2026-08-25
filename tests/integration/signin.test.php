<?php
/**
 * signin.test.php — the create / link / sign-in path against a real WHMCS.
 *
 * Runs ON the dev server (uploaded by signin.test.sh) because it needs WHMCS
 * bootstrapped: localAPI, Capsule, the real tblclients/tblusers/tblauthn tables
 * and the real custom field. Everything it creates it creates through localAPI,
 * and everything it creates it removes again — see cleanup() at the end.
 *
 * The Google token itself is NOT exercised here; it cannot be, because only
 * Google can sign one. GoogleToken::verify is covered exhaustively by
 * tests/unit/google-token.test.php against generated keys. This file starts one
 * step later, from the verified identity that verify() would have returned, and
 * proves the WHMCS half: that a required custom field does not block AddClient,
 * that the link lands in tblauthn_account_links, that a second sign-in reuses
 * the account instead of duplicating it, and that an address WHMCS already
 * knows gets linked rather than re-registered.
 *
 * WARNING: creates and deletes real clients on the target WHMCS. Point it at
 * the dev box only.
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

require_once WEBROOT . '/init.php';

// The DEPLOYED module is what is exercised, so run scripts/deploy-dev.sh first.
$moduleDir = WEBROOT . '/modules/addons/vpnhoodsignin';
require_once $moduleDir . '/lib/Jwt.php';
require_once $moduleDir . '/lib/Http.php';
require_once $moduleDir . '/lib/GoogleToken.php';
require_once $moduleDir . '/lib/SignInGate.php';
require_once $moduleDir . '/lib/AccountLinker.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\VpnHoodSignIn\AccountLinker;
use WHMCS\Module\Addon\VpnHoodSignIn\GoogleToken;
use WHMCS\Module\Addon\VpnHoodSignIn\SignInGate;

$pass = 0;
$fail = 0;
$createdClientIds = [];
$createdSubjects = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "PASS $label\n";
        $pass++;
    } else {
        echo "FAIL $label" . ($detail !== '' ? ": $detail" : '') . "\n";
        $fail++;
    }
}

// ------------------------------------------------------------------ settings
// Configure the addon exactly as an admin would (tbladdonmodules is where
// WHMCS itself keeps addon settings; activation is what creates these rows).

// The Client ID is the one setting whose REAL value matters outside these tests:
// overwrite it and the install can no longer do a live Google sign-in, because
// Google will not mint a token for a client id that does not exist. Remember it
// now and put it back in the cleanup, so running the suite is not a silent way
// to break the dev box for whoever tries the button next.
$realClientId = Capsule::table('tbladdonmodules')
    ->where('module', SignInGate::MODULE)->where('setting', 'GoogleClientId')->value('value');

$settings = [
    'Enabled'               => 'on',
    'GoogleClientId'        => 'integration-test.apps.googleusercontent.com',
    'ButtonPages'           => 'login,register,cart',
    'ButtonSelectors'       => '',
    'ExistingAccountAction' => SignInGate::EXISTING_LINK_NOTIFY,
    'CustomFieldName'       => 'How did you hear about us?',
    'CustomFieldMode'       => SignInGate::MODE_DEFAULT_VALUE,
    'CustomFieldDefault'    => 'Other',
    'DefaultCountry'        => '',
    'CutoffDate'            => date('Y-m-d'),
];
foreach ($settings as $name => $value) {
    $exists = Capsule::table('tbladdonmodules')
        ->where('module', SignInGate::MODULE)->where('setting', $name)->exists();
    if ($exists) {
        Capsule::table('tbladdonmodules')
            ->where('module', SignInGate::MODULE)->where('setting', $name)->update(['value' => $value]);
    } else {
        Capsule::table('tbladdonmodules')
            ->insert(['module' => SignInGate::MODULE, 'setting' => $name, 'value' => $value]);
    }
}

// Activating an addon is THREE tblconfiguration lists, not one. ActiveAddonModules
// alone makes WHMCS report the addon as active from every API it exposes
// (getActiveModules, isActive, isModuleEnabled all say yes) while still never
// loading its hooks.php — because hook loading is driven by the separate,
// cached AddonModulesHooks list that WHMCS writes when an admin activates the
// module through the UI. Miss it and the button silently never renders, with
// nothing anywhere to say why. AddonModulesPerms is what puts the addon in the
// admin menu.
foreach (['ActiveAddonModules', 'AddonModulesHooks'] as $listSetting) {
    $current = Capsule::table('tblconfiguration')->where('setting', $listSetting)->value('value');
    $list = array_values(array_filter(array_map('trim', explode(',', (string) $current))));
    if (!in_array(SignInGate::MODULE, $list, true)) {
        $list[] = SignInGate::MODULE;
        Capsule::table('tblconfiguration')->where('setting', $listSetting)
            ->update(['value' => implode(',', $list)]);
        echo "note: added " . SignInGate::MODULE . " to $listSetting\n";
    }
}

$perms = Capsule::table('tblconfiguration')->where('setting', 'AddonModulesPerms')->value('value');
$permMap = @unserialize((string) $perms) ?: [];
if (!isset($permMap[1][SignInGate::MODULE])) {
    $permMap[1][SignInGate::MODULE] = 'VpnHood! Sign-In';
    Capsule::table('tblconfiguration')->where('setting', 'AddonModulesPerms')
        ->update(['value' => serialize($permMap)]);
    echo "note: granted admin role 1 access to " . SignInGate::MODULE . "\n";
}

foreach (['access' => '1', 'version' => '1.0.0'] as $name => $value) {
    if (!Capsule::table('tbladdonmodules')->where('module', SignInGate::MODULE)
            ->where('setting', $name)->exists()) {
        Capsule::table('tbladdonmodules')
            ->insert(['module' => SignInGate::MODULE, 'setting' => $name, 'value' => $value]);
    }
}

echo "\n== settings / field resolution ==\n";

$fieldId = SignInGate::customFieldId();
check('custom field resolves by name', $fieldId > 0, "got id $fieldId");
check('field id is NOT the hardcoded 6 from the legacy hook', $fieldId !== 6, "id=$fieldId");
check('default value is one of the field options', SignInGate::defaultValueIsValid(),
    'options: ' . implode('|', SignInGate::customFieldOptions()));

$country = SignInGate::resolveCountry();
check('country falls back to a 2-letter code', (bool) preg_match('/^[A-Z]{2}$/', $country), "got '$country'");

// ------------------------------------------------------------ brand-new user

echo "\n== a Google account WHMCS has never seen ==\n";

$suffix = bin2hex(random_bytes(4));
$newEmail = "signin-new-$suffix@vpnhood.test";
$newSubject = '9' . random_int(100000000, 999999999) . random_int(100000, 999999);
$createdSubjects[] = $newSubject;

$identity = [
    'subject'   => $newSubject,
    'email'     => $newEmail,
    'firstName' => 'Signin',
    'lastName'  => 'Tester',
    'name'      => 'Signin Tester',
    'claims'    => ['sub' => $newSubject, 'email' => $newEmail, 'email_verified' => true],
];

$linker = new AccountLinker();

// Watch WHMCS's own activity log across the call. It is the only place that
// records whether a mail went out or was dropped by a hook — the verification
// mail is a user-type message and never reaches tblemails either way.
$logMark = (int) Capsule::table('tblactivitylog')->max('id');
$result = $linker->signIn($identity);
$signupLog = implode("\n", Capsule::table('tblactivitylog')
    ->where('id', '>', $logMark)->orderBy('id')->pluck('description')->all());

check('new identity is signed in', ($result['status'] ?? '') === AccountLinker::STATUS_LOGGED_IN,
    json_encode($result));
check('a redirect URL was minted', !empty($result['redirectUrl']));
check('it reports having created the account', ($result['created'] ?? false) === true);

$clientId = (int) ($result['clientId'] ?? 0);
if ($clientId > 0) {
    $createdClientIds[] = $clientId;
}
check('a client was created', $clientId > 0);

$client = Capsule::table('tblclients')->where('id', $clientId)->first();
check('client email matches the token', $client && strtolower($client->email) === $newEmail);
check('client country was filled', $client && preg_match('/^[A-Z]{2}$/', strtoupper((string) $client->country)));

$userId = (int) Capsule::table('tblusers')->whereRaw('LOWER(email) = ?', [$newEmail])->value('id');
check('a WHMCS user was created', $userId > 0);
check('the email is marked verified (Google already proved it)',
    Capsule::table('tblusers')->where('id', $userId)->whereNotNull('email_verified_at')->exists());

$link = Capsule::table('tblauthn_account_links')
    ->where('provider', GoogleToken::PROVIDER)->where('remote_user_id', $newSubject)->first();
check('the Google link was stored', $link !== null);
check('the link points at the new user', $link && (int) $link->user_id === $userId);

$fieldValue = SignInGate::customFieldValue($clientId);
check('the required custom field was populated', $fieldValue === 'Other', "got '$fieldValue'");

// ------------------------------------------- the verification mail nobody needs

// WHMCS mails the verification link from inside AddClient, before it has even
// returned the client id — so marking the address verified afterwards cannot
// call it back. Left alone, a client who signed in with Google is asked to
// confirm an address Google confirmed seconds earlier, with a link that is
// already spent. The EmailPreSend hook drops it; the welcome mail, which is
// sent inside the same window, must survive.

echo "\n== the verification mail a Google signup does not need ==\n";

$verificationOn = (string) Capsule::table('tblconfiguration')
    ->where('setting', 'EnableEmailVerification')->value('value') === '1';

if (!$verificationOn) {
    echo "SKIP verification-mail checks — EnableEmailVerification is off on this install\n";
} else {
    check('the "Email Address Verification" mail was aborted',
        strpos($signupLog, 'Email Sending Aborted by Hook (Subject: Email Address Verification)') !== false,
        $signupLog);
    check('WHMCS logged the verification message itself as aborted',
        strpos($signupLog, 'Email Verification Message Sending Aborted by Hook') !== false);
}

check('the welcome mail still went out',
    preg_match('/Email Sent to .*\(Welcome\)/', $signupLog) === 1,
    'it is sent inside the same window, and survives only because the hook tests the message name');
check('the suppression window is closed again once AddClient returns',
    !SignInGate::isSuppressingVerificationEmail());

// ------------------------------------------------------ returning user (login)

echo "\n== the same Google account signing in again ==\n";

$clientCountBefore = (int) Capsule::table('tblclients')->count();
$again = $linker->signIn($identity);

check('returning identity is signed in', ($again['status'] ?? '') === AccountLinker::STATUS_LOGGED_IN,
    json_encode($again));
check('it does NOT report creating anything', ($again['created'] ?? true) === false);
check('it resolves to the same client', (int) ($again['clientId'] ?? 0) === $clientId);
check('no second client was created', (int) Capsule::table('tblclients')->count() === $clientCountBefore);
check('no duplicate link row', (int) Capsule::table('tblauthn_account_links')
    ->where('provider', GoogleToken::PROVIDER)->where('remote_user_id', $newSubject)->count() === 1);

// ------------------------------------------------- existing email, new Google

echo "\n== a different Google account on an address WHMCS already knows ==\n";

$otherSubject = '8' . random_int(100000000, 999999999) . random_int(100000, 999999);
$createdSubjects[] = $otherSubject;

$existingIdentity = $identity;
$existingIdentity['subject'] = $otherSubject;

$linked = $linker->signIn($existingIdentity);
check('existing address is linked and signed in',
    ($linked['status'] ?? '') === AccountLinker::STATUS_LOGGED_IN, json_encode($linked));
check('it links rather than registers', ($linked['created'] ?? true) === false);
check('it resolves to the same client', (int) ($linked['clientId'] ?? 0) === $clientId);
check('the second Google identity is now linked too', Capsule::table('tblauthn_account_links')
    ->where('provider', GoogleToken::PROVIDER)->where('remote_user_id', $otherSubject)->exists());

// -------------------------------------------------------- two-factor refusal

echo "\n== an account protected by WHMCS two-factor ==\n";

Capsule::table('tblusers')->where('id', $userId)->update(['second_factor' => 'TotpAuth']);
$twoFactor = $linker->signIn($identity);
check('2FA account is NOT signed in through Google',
    ($twoFactor['status'] ?? '') === AccountLinker::STATUS_TWO_FACTOR, json_encode($twoFactor));
check('no redirect URL is handed out for a 2FA account', empty($twoFactor['redirectUrl']));
Capsule::table('tblusers')->where('id', $userId)->update(['second_factor' => '']);

// --------------------------------------------------------------- ask-once gate

echo "\n== the ask-once gate scope ==\n";

Capsule::table('tbladdonmodules')->where('module', SignInGate::MODULE)
    ->where('setting', 'CustomFieldMode')->update(['value' => SignInGate::MODE_ASK_ONCE]);

// settings() caches per process, so scope has to be re-read in a fresh one;
// assert on the pieces the gate is built from instead.
check('the Google-signed-up client is recognised as Google-linked',
    SignInGate::hasGoogleLink($clientId));
check('a client WITH an answer is not considered unanswered',
    SignInGate::customFieldValue($clientId) !== '');

Capsule::table('tbladdonmodules')->where('module', SignInGate::MODULE)
    ->where('setting', 'CustomFieldMode')->update(['value' => SignInGate::MODE_DEFAULT_VALUE]);

// ------------------------------------------------------------------- cleanup

echo "\n== cleanup ==\n";

foreach ($createdSubjects as $subject) {
    Capsule::table('tblauthn_account_links')
        ->where('provider', GoogleToken::PROVIDER)->where('remote_user_id', $subject)->delete();
}
echo "removed " . count($createdSubjects) . " account link(s)\n";

foreach ($createdClientIds as $id) {
    $deleted = localAPI('DeleteClient', ['clientid' => $id, 'deleteusers' => true]);
    echo "DeleteClient #$id: " . ($deleted['result'] ?? '?')
        . (isset($deleted['message']) ? ' (' . $deleted['message'] . ')' : '') . "\n";
}

$leftover = Capsule::table('tblclients')->where('email', $newEmail)->exists();
if ($realClientId !== null && $realClientId !== 'integration-test.apps.googleusercontent.com') {
    Capsule::table('tbladdonmodules')
        ->where('module', SignInGate::MODULE)->where('setting', 'GoogleClientId')
        ->update(['value' => $realClientId]);
    echo "restored GoogleClientId to $realClientId\n";
}

check('the test client is gone', !$leftover);

echo "\n----\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
