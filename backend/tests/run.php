<?php
declare(strict_types=1);

/**
 * Minimal test runner (no PHPUnit needed): php backend/tests/run.php [filter]
 * Every *Test.php file calls test('name', fn () => …) and uses the assert helpers below.
 * Exit code 1 when a test fails, so CI turns red.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
define('POSTVAK_ROOT', dirname(__DIR__));
require POSTVAK_ROOT . '/vendor/autoload.php';

Postvak\Config::load([
    'app_url' => 'https://postvak.test',
    'app_key' => base64_encode(random_bytes(32)),
    'allow_private_hosts' => false,
]);

final class AssertionFailed extends RuntimeException {}

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__file'], $name, $fn];
}
function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function assertTrue(bool $value, string $msg = 'expected true'): void
{
    if (!$value) {
        throw new AssertionFailed($msg);
    }
}
function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . "'$needle' not found in '" . mb_substr($haystack, 0, 300) . "'");
    }
}
function assertNotContains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . "'$needle' should not be in '" . mb_substr($haystack, 0, 300) . "'");
    }
}
function assertThrows(callable $fn, string $class = Throwable::class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new AssertionFailed("expected $class, got " . $e::class . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected $class, nothing was thrown");
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    $GLOBALS['__file'] = basename($file, '.php');
    require $file;
}

$failed = 0;
$ran = 0;
foreach ($GLOBALS['__tests'] as [$file, $name, $fn]) {
    if ($filter !== '' && stripos("$file $name", $filter) === false) {
        continue;
    }
    $ran++;
    try {
        $fn();
        echo "  ✓ $file: $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $file: $name\n      " . $e->getMessage() . ($e instanceof AssertionFailed ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . "\n";
    }
}
echo "\n$ran tests, " . ($failed ? "$failed failed" : 'all passed') . "\n";
exit($failed ? 1 : 0);
