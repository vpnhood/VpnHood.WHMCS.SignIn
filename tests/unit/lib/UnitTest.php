<?php
/**
 * UnitTest.php — tiny dependency-free unit test harness (pure PHP, no WHMCS,
 * no Composer). Test files register cases with test('name', fn) and assert
 * with the helpers below; run.php executes everything and reports.
 */

final class AssertionFailure extends \Exception
{
}

final class UnitTest
{
    /** @var array<int,array{name:string, fn:callable}> */
    private static array $tests = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(): int
    {
        $pass = 0;
        $fail = 0;
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                echo "PASS {$test['name']}\n";
                $pass++;
            } catch (AssertionFailure $e) {
                echo "FAIL {$test['name']}: {$e->getMessage()}\n";
                $fail++;
            } catch (\Throwable $e) {
                echo "FAIL {$test['name']}: unexpected " . get_class($e) . ": {$e->getMessage()}\n";
                $fail++;
            }
        }
        echo "----\n$pass passed, $fail failed\n";
        return $fail > 0 ? 1 : 0;
    }
}

function test(string $name, callable $fn): void
{
    UnitTest::add($name, $fn);
}

function assertTrue(mixed $condition, string $message = 'expected true'): void
{
    if ($condition !== true) {
        throw new AssertionFailure($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message === '' ? '' : "$message — ";
        throw new AssertionFailure(
            $prefix . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

/**
 * Assert that $fn throws (optionally an instance of $class whose message
 * contains $messageNeedle). Returns the caught exception for extra checks.
 */
function assertThrows(callable $fn, string $class = \Throwable::class, string $messageNeedle = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new AssertionFailure('threw ' . get_class($e) . ", expected $class: {$e->getMessage()}");
        }
        if ($messageNeedle !== '' && !str_contains($e->getMessage(), $messageNeedle)) {
            throw new AssertionFailure("exception message '{$e->getMessage()}' does not contain '$messageNeedle'");
        }
        return $e;
    }
    throw new AssertionFailure("expected $class, nothing thrown");
}

/**
 * Locate an openssl.cnf, or null when OpenSSL can find its own.
 *
 * The Windows PHP builds ship a config but do not point OpenSSL at it, and
 * putenv('OPENSSL_CONF=...') is too late by the time the extension is loaded.
 * The documented per-call override is what actually works, so key generation in
 * the tests threads this through as the 'config' option. PHP_BINDIR is checked
 * second because it is unreliable on Windows (it can read C:\php while php.ini
 * really lives under the WAMP tree).
 */
function opensslConfigPath(): ?string
{
    static $resolved = false;
    static $path = null;
    if ($resolved) {
        return $path;
    }
    $resolved = true;

    $fromEnv = getenv('OPENSSL_CONF');
    if (is_string($fromEnv) && is_file($fromEnv)) {
        return $path = $fromEnv;
    }

    $iniFile = php_ini_loaded_file();
    $bases = [$iniFile !== false ? dirname($iniFile) : null, PHP_BINDIR];
    foreach ($bases as $base) {
        if ($base === null) {
            continue;
        }
        $candidate = $base . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR
            . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (is_file($candidate)) {
            return $path = $candidate;
        }
    }

    return $path = null;
}

/**
 * Generate an RSA keypair for a test fixture.
 *
 * @return array{0:string, 1:string} [private PEM, public PEM]
 */
function newTestRsaKey(int $bits = 2048): array
{
    $options = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $configPath = opensslConfigPath();
    if ($configPath !== null) {
        $options['config'] = $configPath;
    }

    $key = openssl_pkey_new($options);
    if ($key === false) {
        throw new \RuntimeException(
            'openssl_pkey_new() failed - no usable openssl.cnf. Set OPENSSL_CONF to one. '
            . 'Last OpenSSL error: ' . (openssl_error_string() ?: 'none')
        );
    }

    $privatePem = '';
    openssl_pkey_export($key, $privatePem, null, $configPath !== null ? ['config' => $configPath] : []);

    return [$privatePem, openssl_pkey_get_details($key)['key']];
}
