<?php
use Postvak\Crypto;
use Postvak\Migrator;

test('encryption round-trips and is not deterministic', function () {
    $a = Crypto::encrypt('geheim wachtwoord');
    assertSame('geheim wachtwoord', Crypto::decrypt($a));
    assertTrue($a !== Crypto::encrypt('geheim wachtwoord'));
});

test('every migration splits into statements that each do one thing', function () {
    $m = new ReflectionMethod(Migrator::class, 'statements');
    foreach (glob(POSTVAK_ROOT . '/database/migrations/*.sql') as $file) {
        $statements = $m->invoke(null, (string) file_get_contents($file));
        assertTrue($statements !== [], basename($file) . ' has no statements');
        foreach ($statements as $st) {
            $count = preg_match_all('/^\s*(CREATE|ALTER|INSERT|UPDATE|DELETE|DROP)\b/mi', $st);
            assertSame(1, $count, basename($file) . ': statement contains ' . $count . ' commands');
        }
        assertContains('schema_migrations', end($statements), basename($file) . ' must register itself last');
    }
});

test('constraint names are unique in schema.sql', function () {
    preg_match_all('/CONSTRAINT\s+(\w+)/i', (string) file_get_contents(POSTVAK_ROOT . '/database/schema.sql'), $m);
    $dupes = array_keys(array_filter(array_count_values($m[1]), static fn ($n) => $n > 1));
    assertSame([], $dupes);
});

test('schema.sql registers every migration', function () {
    $schema = (string) file_get_contents(POSTVAK_ROOT . '/database/schema.sql');
    foreach (glob(POSTVAK_ROOT . '/database/migrations/*.sql') as $file) {
        assertContains("'" . basename($file, '.sql') . "'", $schema);
    }
});
