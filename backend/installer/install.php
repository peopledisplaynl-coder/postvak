<?php
declare(strict_types=1);

/**
 * Postvak installer — part of the release zip (public/install.php), not of the repository's web root.
 *
 * Works only while app/config/config.php does not exist: checks the server, tests the database,
 * creates the tables, writes config.php with a new encryption key, and then deletes itself.
 * Run it right after uploading.
 */

$apiDir = __DIR__ . '/api';
$appDir = is_file($apiDir . '/app-path.php') ? (string) require $apiDir . '/app-path.php' : __DIR__ . '/../app';
$appDir = rtrim(str_replace('\\', '/', $appDir), '/');
$configFile = $appDir . '/config/config.php';

// ------------------------------------------------------------------ language
$lang = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'nl') ? 'nl' : 'en';
if (in_array($_GET['lang'] ?? '', ['nl', 'en'], true)) {
    $lang = $_GET['lang'];
}
$T = [
    'en' => [
        'title' => 'Install Postvak', 'intro' => 'Fill in this form once. Postvak creates its tables and writes config.php.',
        'done_title' => 'Postvak is installed', 'installed' => 'Postvak is already installed (config.php exists). Delete install.php from the server.',
        'checks' => 'Server check', 'php' => 'PHP 8.2 or newer', 'ext' => 'PHP extension', 'appdir' => 'Application folder found', 'writable' => 'config folder writable',
        'storage' => 'storage folder writable', 'https' => 'HTTPS', 'optional' => 'recommended',
        'db' => 'Database', 'db_hint' => 'An empty MySQL/MariaDB database. Your hosting control panel shows these details.',
        'host' => 'Host', 'name' => 'Database name', 'user' => 'User', 'pass' => 'Password',
        'site' => 'Postvak', 'url' => 'Address of Postvak', 'mail' => 'Mail server', 'mail_hint' => '{domain} is replaced by the part after the @. Most hosts: mail.{domain}.',
        'imap' => 'IMAP server', 'smtp' => 'SMTP server', 'domains' => 'Who may log in (optional)', 'domains_hint' => 'Domains, comma separated, e.g. example.com. Empty = every mailbox your mail server accepts.',
        'admin' => 'Admin address (optional)', 'admin_hint' => 'Mailbox that may open the admin page. Empty = the first person who logs in.',
        'submit' => 'Install', 'fix' => 'Fix the problems above first.', 'db_error' => 'Could not connect to the database', 'write_error' => 'Could not write config.php',
        'schema_error' => 'Creating the tables failed', 'self_deleted' => 'install.php has been deleted.', 'delete_yourself' => 'Delete install.php from the server now.',
        'next' => 'Next steps', 'next1' => 'Log in with your mailbox address and password.', 'next2' => 'Set up the background task (cron job) for notifications, send later and snooze: run app/cron.php every 5 minutes.',
        'open' => 'Open Postvak', 'app_missing' => 'The application folder was not found at %s. Put the "app" folder next to the web root, or change the path in api/app-path.php.',
    ],
    'nl' => [
        'title' => 'Postvak installeren', 'intro' => 'Vul dit formulier één keer in. Postvak maakt zijn tabellen aan en schrijft config.php.',
        'done_title' => 'Postvak is geïnstalleerd', 'installed' => 'Postvak is al geïnstalleerd (config.php bestaat). Verwijder install.php van de server.',
        'checks' => 'Servercontrole', 'php' => 'PHP 8.2 of nieuwer', 'ext' => 'PHP-extensie', 'appdir' => 'Programmamap gevonden', 'writable' => 'config-map schrijfbaar',
        'storage' => 'storage-map schrijfbaar', 'https' => 'HTTPS', 'optional' => 'aanbevolen',
        'db' => 'Database', 'db_hint' => 'Een lege MySQL/MariaDB-database. De gegevens staan in het beheerpaneel van je hosting.',
        'host' => 'Host', 'name' => 'Databasenaam', 'user' => 'Gebruiker', 'pass' => 'Wachtwoord',
        'site' => 'Postvak', 'url' => 'Adres van Postvak', 'mail' => 'Mailserver', 'mail_hint' => '{domain} wordt vervangen door het deel na de @. Bij de meeste hosts: mail.{domain}.',
        'imap' => 'IMAP-server', 'smtp' => 'SMTP-server', 'domains' => 'Wie mag inloggen (optioneel)', 'domains_hint' => 'Domeinen, gescheiden door komma\'s, bijv. voorbeeld.nl. Leeg = elke mailbox die je mailserver accepteert.',
        'admin' => 'Beheerdersadres (optioneel)', 'admin_hint' => 'Mailbox die de beheerpagina mag openen. Leeg = de eerste die inlogt.',
        'submit' => 'Installeren', 'fix' => 'Los eerst de problemen hierboven op.', 'db_error' => 'Kan geen verbinding maken met de database', 'write_error' => 'Kan config.php niet schrijven',
        'schema_error' => 'Het aanmaken van de tabellen is mislukt', 'self_deleted' => 'install.php is verwijderd.', 'delete_yourself' => 'Verwijder install.php nu van de server.',
        'next' => 'Volgende stappen', 'next1' => 'Log in met het adres en wachtwoord van je mailbox.', 'next2' => 'Stel de achtergrondtaak (cronjob) in voor meldingen, later versturen en snoozen: laat app/cron.php elke 5 minuten draaien.',
        'open' => 'Postvak openen', 'app_missing' => 'De programmamap is niet gevonden op %s. Zet de map "app" naast de webroot, of pas het pad aan in api/app-path.php.',
    ],
][$lang];
$h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ------------------------------------------------------------------ checks
$https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$checks = [[$T['php'] . ' (' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>='), true]];
foreach (['mbstring', 'openssl', 'sodium', 'pdo_mysql', 'iconv', 'dom'] as $ext) {
    $checks[] = ["{$T['ext']} $ext", extension_loaded($ext), true];
}
$checks[] = ["{$T['ext']} curl", extension_loaded('curl'), false];
$appFound = is_file($appDir . '/bootstrap.php') && is_dir($appDir . '/vendor');
$checks[] = [$T['appdir'], $appFound, true];
$checks[] = [$T['writable'], $appFound && is_writable($appDir . '/config'), true];
$checks[] = [$T['storage'], $appFound && (is_writable($appDir . '/storage') || @mkdir($appDir . '/storage', 0775, true)), true];
$checks[] = [$T['https'], $https, false];
$ready = !in_array(false, array_map(static fn ($c) => $c[1] || !$c[2], $checks), true);

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$defaults = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'app_url' => ($https ? 'https' : 'http') . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\'),
    'imap_host' => 'mail.{domain}', 'imap_port' => '993', 'smtp_host' => 'mail.{domain}', 'smtp_port' => '465',
    'domains' => '', 'admin' => '',
];
$in = array_merge($defaults, array_intersect_key(array_map(static fn ($v) => trim((string) $v), $_POST), $defaults));
$errors = [];
$done = false;
$selfDeleted = false;

// ------------------------------------------------------------------ install
if (!is_file($configFile) && $ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $in['db_host'], $in['db_name']);
        $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        $errors[] = $T['db_error'] . ': ' . $e->getMessage();
    }

    if (!$errors) {
        try {
            $sql = (string) file_get_contents($appDir . '/database/schema.sql');
            $sql = preg_replace('/(^|\s)--(\s.*)?$/m', '', $sql) ?? $sql;
            foreach (array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql) ?: [])) as $statement) {
                $pdo->exec($statement);
            }
        } catch (Throwable $e) {
            $errors[] = $T['schema_error'] . ': ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $list = static fn (string $s) => array_values(array_filter(array_map(static fn ($x) => strtolower(trim($x)), explode(',', $s))));
        $config = [
            'app_url'  => rtrim($in['app_url'], '/'),
            'debug'    => false,
            'app_key'  => base64_encode(random_bytes(32)),
            'database' => ['dsn' => $dsn, 'username' => $in['db_user'], 'password' => $in['db_pass']],
            'session'  => ['name' => 'postvak_sid', 'lifetime' => 60 * 60 * 24 * 30],
            'rate_limit' => ['window_minutes' => 15, 'max_per_ip' => 20, 'max_per_email' => 5],
            'default_servers' => [
                'imap_host' => $in['imap_host'], 'imap_port' => (int) $in['imap_port'], 'imap_encryption' => (int) $in['imap_port'] === 143 ? 'tls' : 'ssl',
                'smtp_host' => $in['smtp_host'], 'smtp_port' => (int) $in['smtp_port'], 'smtp_encryption' => (int) $in['smtp_port'] === 587 ? 'tls' : 'ssl',
                'validate_cert' => true,
            ],
            'admins' => $list($in['admin']),
            'allow_private_hosts' => false,
            'login_custom_servers' => false,
            'allowed_login_domains' => $list($in['domains']),
            'max_attachment_mb' => 20,
            'cron_key' => '',
        ];
        $php = "<?php\n// Written by the Postvak installer on " . date('Y-m-d H:i') . ". See config.example.php for all options.\n"
            . "// Keep app_key safe: without it the stored mailbox passwords cannot be read.\n\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
            $errors[] = $T['write_error'] . ' (' . $configFile . ')';
        } else {
            @chmod($configFile, 0640);
            $done = true;
            $selfDeleted = @unlink(__FILE__);
        }
    }
}
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($done ? $T['done_title'] : $T['title']) ?></title>
<style>
  :root { --accent: #3646D9; --line: #DCE1E8; --muted: #677385; --bg: #F2F4F7; --ok: #0F8A5F; --bad: #D23F3F; --warn: #C98A06 }
  * { box-sizing: border-box }
  body { margin: 0; background: var(--bg); color: #18202B; font: 15px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif }
  main { max-width: 620px; margin: 0 auto; padding: 32px 16px 64px }
  h1 { font-size: 26px; margin: 12px 0 4px } h2 { font-size: 16px; margin: 28px 0 8px }
  p.muted, .hint { color: var(--muted) } .hint { font-size: 13px; margin: 4px 0 0 }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 16px }
  ul.checks { list-style: none; margin: 0; padding: 0 } ul.checks li { padding: 4px 0; display: flex; gap: 8px; align-items: center }
  .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0 } .ok { background: var(--ok) } .bad { background: var(--bad) } .warn { background: var(--warn) }
  label { display: block; margin-top: 12px; font-weight: 500; font-size: 14px }
  input { width: 100%; margin-top: 4px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; font: inherit; background: #fff }
  input:focus { outline: none; border-color: var(--accent) }
  .row { display: grid; grid-template-columns: 1fr 110px; gap: 8px }
  button, a.button { display: inline-block; margin-top: 20px; padding: 12px 20px; background: var(--accent); color: #fff; border: 0; border-radius: 12px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none }
  button:disabled { opacity: .5; cursor: not-allowed }
  .error { background: #FBEAEA; color: var(--bad); border-radius: 12px; padding: 12px 14px; margin-top: 16px }
  .lang { float: right; font-size: 13px } .lang a { color: var(--muted) }
  code { background: #E8EBF0; padding: 1px 6px; border-radius: 6px }
</style>
</head>
<body>
<main>
  <span class="lang"><a href="?lang=nl">Nederlands</a> · <a href="?lang=en">English</a></span>
  <svg width="48" height="48" viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#3646D9"/><path d="M120 176c0-17.7 14.3-32 32-32h208c17.7 0 32 14.3 32 32v160c0 17.7-14.3 32-32 32H152c-17.7 0-32-14.3-32-32V176z" fill="#fff"/><path d="M136 168l120 92 120-92" fill="none" stroke="#3646D9" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/></svg>

<?php if ($done): ?>
  <h1><?= $h($T['done_title']) ?></h1>
  <p class="<?= $selfDeleted ? 'muted' : 'error' ?>"><?= $h($selfDeleted ? $T['self_deleted'] : $T['delete_yourself']) ?></p>
  <h2><?= $h($T['next']) ?></h2>
  <ol class="card"><li><?= $h($T['next1']) ?></li><li><?= $h($T['next2']) ?></li></ol>
  <a class="button" href="./"><?= $h($T['open']) ?></a>

<?php elseif (is_file($configFile)): ?>
  <h1><?= $h($T['title']) ?></h1>
  <p class="error"><?= $h($T['installed']) ?></p>

<?php else: ?>
  <h1><?= $h($T['title']) ?></h1>
  <p class="muted"><?= $h($T['intro']) ?></p>

  <h2><?= $h($T['checks']) ?></h2>
  <ul class="checks card">
  <?php foreach ($checks as [$label, $ok, $required]): ?>
    <li><span class="dot <?= $ok ? 'ok' : ($required ? 'bad' : 'warn') ?>"></span><?= $h($label) ?><?= $required ? '' : ' <span class="hint">(' . $h($T['optional']) . ')</span>' ?></li>
  <?php endforeach ?>
  </ul>
  <?php if (!$appFound): ?><p class="error"><?= $h(sprintf($T['app_missing'], $appDir)) ?></p><?php endif ?>
  <?php foreach ($errors as $e): ?><p class="error"><?= $h($e) ?></p><?php endforeach ?>

  <form method="post" autocomplete="off">
    <h2><?= $h($T['db']) ?></h2>
    <div class="card">
      <p class="hint"><?= $h($T['db_hint']) ?></p>
      <label><?= $h($T['host']) ?><input name="db_host" value="<?= $h($in['db_host']) ?>" required></label>
      <label><?= $h($T['name']) ?><input name="db_name" value="<?= $h($in['db_name']) ?>" required></label>
      <label><?= $h($T['user']) ?><input name="db_user" value="<?= $h($in['db_user']) ?>" required></label>
      <label><?= $h($T['pass']) ?><input name="db_pass" type="password" value="<?= $h($in['db_pass']) ?>"></label>
    </div>

    <h2><?= $h($T['site']) ?></h2>
    <div class="card">
      <label><?= $h($T['url']) ?><input name="app_url" value="<?= $h($in['app_url']) ?>" required></label>
      <label><?= $h($T['domains']) ?><input name="domains" value="<?= $h($in['domains']) ?>" placeholder="example.com"></label>
      <p class="hint"><?= $h($T['domains_hint']) ?></p>
      <label><?= $h($T['admin']) ?><input name="admin" type="email" value="<?= $h($in['admin']) ?>"></label>
      <p class="hint"><?= $h($T['admin_hint']) ?></p>
    </div>

    <h2><?= $h($T['mail']) ?></h2>
    <div class="card">
      <p class="hint"><?= $h($T['mail_hint']) ?></p>
      <div class="row">
        <label><?= $h($T['imap']) ?><input name="imap_host" value="<?= $h($in['imap_host']) ?>" required></label>
        <label>Port<input name="imap_port" value="<?= $h($in['imap_port']) ?>" inputmode="numeric" required></label>
      </div>
      <div class="row">
        <label><?= $h($T['smtp']) ?><input name="smtp_host" value="<?= $h($in['smtp_host']) ?>" required></label>
        <label>Port<input name="smtp_port" value="<?= $h($in['smtp_port']) ?>" inputmode="numeric" required></label>
      </div>
    </div>

    <button type="submit" <?= $ready ? '' : 'disabled' ?>><?= $h($T['submit']) ?></button>
    <?php if (!$ready): ?><p class="hint"><?= $h($T['fix']) ?></p><?php endif ?>
  </form>
<?php endif ?>
</main>
</body>
</html>
