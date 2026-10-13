<?php
/**
 * pending-upgrade.test.php — the cron's catch-up of an update (hooks.php AfterCronJob →
 * vpnhoodsignin_runPendingUpgrade) on the dev WHMCS: a recorded version older than the files
 * becomes the files' version with one activity-log line, a second run changes nothing, and a newer
 * one (a rollback's) is recorded as the files' too. WHMCS loads hooks.php only while the addon is
 * in its AddonModulesHooks list (docs/ARCHITECTURE.md, "Activation is three lists, not one"), so
 * that comes first.
 *
 * Runs ON the dev server, uploaded by pending-upgrade.test.sh. Writes only the module's own
 * version row, and leaves it at the files' version, as WHMCS would. Dev box only.
 */

error_reporting(E_ALL);
const WEBROOT = '/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html';

require_once WEBROOT . '/init.php';
require_once WEBROOT . '/modules/addons/vpnhoodsignin/vpnhoodsignin.php';

use WHMCS\Database\Capsule;

$pass = 0;
$fail = 0;

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

function recordedVersion(): ?string
{
    $value = Capsule::table('tbladdonmodules')->where('module', 'vpnhoodsignin')->where('setting', 'version')->value('value');
    return $value === null ? null : (string) $value;
}

function recordVersion(string $version): void
{
    Capsule::table('tbladdonmodules')->where('module', 'vpnhoodsignin')->where('setting', 'version')->update(['value' => $version]);
}

function logLines(int $afterId, string $description): int
{
    return Capsule::table('tblactivitylog')->where('id', '>', $afterId)->where('description', $description)->count();
}

if (recordedVersion() === null) {
    check('vpnhoodsignin is active on the dev install', false, 'run signin.test.sh first, which activates it');
    exit(1);
}

$hookList = explode(',', (string) Capsule::table('tblconfiguration')->where('setting', 'AddonModulesHooks')->value('value'));
check('vpnhoodsignin is in AddonModulesHooks, so WHMCS loads its cron hook', in_array('vpnhoodsignin', $hookList, true));

$config = vpnhoodsignin_config();
$version = (string) $config['version'];
$marker = (int) Capsule::table('tblactivitylog')->max('id');
$line = "{$config['name']} updated from 0.0.1 to $version.";

try {
    recordVersion('0.0.1');
    vpnhoodsignin_runPendingUpgrade();
    check("an older recorded version becomes $version, with one activity-log line",
        recordedVersion() === $version && logLines($marker, $line) === 1,
        json_encode(['recorded' => recordedVersion(), 'lines' => logLines($marker, $line)]));

    vpnhoodsignin_runPendingUpgrade();
    check('a second run changes nothing',
        recordedVersion() === $version && logLines($marker, $line) === 1,
        json_encode(['recorded' => recordedVersion(), 'lines' => logLines($marker, $line)]));

    recordVersion('99.0.0');
    vpnhoodsignin_runPendingUpgrade();
    check("a rollback's older files are recorded as $version", recordedVersion() === $version,
        json_encode(['recorded' => recordedVersion()]));
} finally {
    recordVersion($version);
}

echo "\n----\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
