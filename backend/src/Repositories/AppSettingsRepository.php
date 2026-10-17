<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Config;
use Postvak\Database;
use Throwable;

/**
 * App-wide settings that an admin can change on the admin page.
 * A stored value overrides the matching value in config.php.
 */
final class AppSettingsRepository
{
    private static ?array $cache = null;

    public function get(string $name, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    public function set(string $name, mixed $value): void
    {
        Database::run(
            'INSERT INTO app_settings (name, value_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)',
            [$name, json_encode($value)]
        );
        self::$cache = null;
    }

    public function delete(string $name): void
    {
        Database::run('DELETE FROM app_settings WHERE name = ?', [$name]);
        self::$cache = null;
    }

    /** Domains allowed to log in: admin page first, then config.php. Empty = everyone. */
    public function allowedLoginDomains(): array
    {
        $value = $this->get('allowed_login_domains');
        $list = is_array($value) ? $value : (array) Config::get('allowed_login_domains', []);
        return array_values(array_filter(array_map(static fn ($d) => strtolower(trim((string) $d)), $list)));
    }

    /** Default preferences for users who have not changed them. */
    public function userDefaults(): array
    {
        $value = $this->get('user_defaults');
        return is_array($value) ? $value : [];
    }

    private function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Database::all('SELECT name, value_json FROM app_settings');
        } catch (Throwable) {
            return self::$cache = []; // table does not exist yet (before migration 002)
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name']] = json_decode($row['value_json'], true);
        }
        return self::$cache = $out;
    }
}
