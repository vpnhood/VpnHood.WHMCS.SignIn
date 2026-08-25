<?php
/**
 * run.php — execute every tests/unit/*.test.php with the tiny harness in
 * lib/UnitTest.php. Pure PHP 8.1+; needs neither WHMCS nor Composer, so it
 * runs anywhere a php binary exists (locally, in CI, or on the dev server).
 *
 * Module lib classes guard themselves with VPNHOODSIGNIN_TEST so they can be
 * loaded outside WHMCS here. Classes that need WHMCS itself (SignInGate,
 * AccountLinker) are covered by tests/integration instead.
 */

define('VPNHOODSIGNIN_TEST', true);
error_reporting(E_ALL);

/*
 * openssl_pkey_new() needs an openssl.cnf. The Windows PHP builds ship one but
 * do not point at it, and PHP_BINDIR is unreliable there (it reads C:\php on
 * this machine while php.ini actually lives under the WAMP tree). Without a
 * usable config the tests' key generation returns false and every crypto case
 * dies in its fixture instead of its assertion — which looks like a broken test
 * suite rather than a broken environment. Harmless on Linux, where OpenSSL
 * finds the system config by itself.
 */
if (getenv('OPENSSL_CONF') === false) {
    $iniDir = php_ini_loaded_file() !== false ? dirname(php_ini_loaded_file()) : null;
    foreach ([$iniDir, PHP_BINDIR] as $base) {
        if ($base === null) {
            continue;
        }
        $candidate = $base . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl'
            . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (is_file($candidate)) {
            putenv('OPENSSL_CONF=' . $candidate);
            break;
        }
    }
}

require __DIR__ . '/lib/UnitTest.php';

// Module classes under test resolve relative to the repo layout; test files
// use SIGNIN_LIB . '/<Class>.php'.
define('SIGNIN_LIB', dirname(__DIR__, 2) . '/modules/addons/vpnhoodsignin/lib');

$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);
if ($files === []) {
    fwrite(STDERR, "no unit test files found\n");
    exit(1);
}
foreach ($files as $file) {
    require $file;
}

exit(UnitTest::run());
