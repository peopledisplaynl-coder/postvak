<?php
/**
 * Postvak configuration.
 *
 * Copy this file to config.php and fill in the values.
 * config.php is never committed to git (see .gitignore).
 *
 * On shared hosting, place the whole "app" folder OUTSIDE the public web root
 * when your host allows it. If it has to live inside the web root, the
 * .htaccess in that folder blocks direct access.
 */

return [
    // Full public URL, without trailing slash.
    'app_url' => 'https://mail.example.com',

    // Show detailed errors in API responses. Never enable in production.
    'debug' => false,

    // 32-byte key for encrypting stored mailbox passwords, base64 encoded.
    // Generate one with:  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    // WARNING: if you lose or change this key, all stored passwords become unreadable
    // and users have to re-enter them.
    'app_key' => 'CHANGE_ME',

    'database' => [
        'dsn'      => 'mysql:host=localhost;dbname=postvak;charset=utf8mb4',
        'username' => 'postvak',
        'password' => 'CHANGE_ME',
    ],

    'session' => [
        'name'     => 'postvak_sid',
        'lifetime' => 60 * 60 * 24 * 14, // 14 days ("remember me")
    ],

    // Login protection: max failed attempts per IP / per address within the window.
    'rate_limit' => [
        'window_minutes' => 15,
        'max_per_ip'     => 20,
        'max_per_email'  => 5,
    ],

    // Default server settings used when someone logs in or adds an account
    // without filling in the advanced server fields.
    // "{domain}" is replaced by the part after the @ of the address.
    'default_servers' => [
        'imap_host'       => 'mail.{domain}',
        'imap_port'       => 993,
        'imap_encryption' => 'ssl',
        'smtp_host'       => 'mail.{domain}',
        'smtp_port'       => 465,
        'smtp_encryption' => 'ssl',
        'validate_cert'   => true,
    ],

    // Allow mail servers on private/local addresses (127.0.0.1, 192.168.x.x).
    // Keep false on shared hosting; only needed when the mail server runs on your own network.
    'allow_private_hosts' => false,

    // Optional: restrict who can log in. Empty array = any mailbox the IMAP server accepts.
    // Example: ['labee.eu', 'example.nl']
    'allowed_login_domains' => [],

    // Maximum total attachment size when sending, in MB.
    'max_attachment_mb' => 20,
];
