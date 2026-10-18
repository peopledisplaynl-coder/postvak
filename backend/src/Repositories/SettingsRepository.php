<?php
declare(strict_types=1);

namespace Postvak\Repositories;

use Postvak\Database;
use Postvak\Http\HttpException;

/**
 * User preferences, stored as JSON. Unknown keys are ignored,
 * values are validated against the allowed options below.
 */
final class SettingsRepository
{
    /** Swipe actions a user can choose from. */
    public const SWIPE_ACTIONS = ['archive', 'trash', 'junk', 'snooze', 'read', 'flag', 'none'];

    public const DEFAULTS = [
        'swipe_left'       => 'trash',
        'swipe_right'      => 'archive',
        'confirm_delete'   => false,
        'undo_seconds'     => 5,
        'theme'            => 'system',        // system | light | dark
        'density'          => 'comfortable',   // comfortable | compact
        'show_images'      => 'ask',           // ask | always | never
        'reading_pane'     => 'right',         // right | off (desktop only)
        'poll_seconds'     => 60,
        'unified_inbox'    => true,            // one inbox for all accounts
        'mark_read_on_open'=> true,
        'sort'             => 'date_desc',     // date_desc | date_asc | from | subject | size_desc
        'conversations'    => true,            // group messages into conversations (date order only)
        'undo_send'        => 5,               // seconds to undo "send" (0 = send right away)
        'language'         => 'auto',          // auto (browser) | nl | en
        'accent'           => 'indigo',        // colour of buttons and highlights
        'text_size'        => 'normal',        // small | normal | large
        // Labels = IMAP keywords. The first five use Thunderbird's keywords, so they show there too.
        'labels'           => [
            ['key' => '$label1', 'name' => '', 'color' => '#D23F3F'],   // Important
            ['key' => '$label2', 'name' => '', 'color' => '#E07B1A'],   // Work
            ['key' => '$label3', 'name' => '', 'color' => '#0F8A5F'],   // Personal
            ['key' => '$label4', 'name' => '', 'color' => '#2C7DA0'],   // To do
            ['key' => '$label5', 'name' => '', 'color' => '#7A5AC8'],   // Later
        ],
    ];

    public const ACCENTS = ['indigo', 'blue', 'teal', 'green', 'orange', 'rose', 'purple', 'graphite'];

    private const RULES = [
        'swipe_left'        => ['enum', self::SWIPE_ACTIONS],
        'swipe_right'       => ['enum', self::SWIPE_ACTIONS],
        'confirm_delete'    => ['bool'],
        'undo_seconds'      => ['int', 0, 30],
        'theme'             => ['enum', ['system', 'light', 'dark']],
        'density'           => ['enum', ['comfortable', 'compact']],
        'show_images'       => ['enum', ['ask', 'always', 'never']],
        'reading_pane'      => ['enum', ['right', 'off']],
        'poll_seconds'      => ['int', 30, 900],
        'unified_inbox'     => ['bool'],
        'mark_read_on_open' => ['bool'],
        'sort'              => ['enum', ['date_desc', 'date_asc', 'from', 'subject', 'size_desc']],
        'conversations'     => ['bool'],
        'undo_send'         => ['int', 0, 30],
        'language'          => ['enum', ['auto', 'nl', 'en']],
        'accent'            => ['enum', self::ACCENTS],
        'text_size'         => ['enum', ['small', 'normal', 'large']],
        'labels'            => ['labels'],
    ];

    public function get(int $userId): array
    {
        $row = Database::one('SELECT settings_json FROM user_settings WHERE user_id = ?', [$userId]);
        $stored = $row ? (json_decode($row['settings_json'], true) ?: []) : [];
        return array_merge(self::defaults(), array_intersect_key($stored, self::DEFAULTS));
    }

    /** Built-in defaults, overridden by the defaults an admin set on the admin page. */
    public static function defaults(): array
    {
        $admin = (new AppSettingsRepository())->userDefaults();
        $valid = [];
        foreach (array_intersect_key($admin, self::DEFAULTS) as $key => $value) {
            try {
                $valid[$key] = (new self())->validate($key, $value);
            } catch (\Throwable) {
            }
        }
        return array_merge(self::DEFAULTS, $valid);
    }

    /** Validate a whole set of defaults (used by the admin page). */
    public function validateAll(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (isset(self::RULES[$key])) {
                $out[$key] = $this->validate($key, $value);
            }
        }
        return $out;
    }

    public function update(int $userId, array $changes): array
    {
        $settings = $this->get($userId);
        foreach ($changes as $key => $value) {
            if (!isset(self::RULES[$key])) {
                continue;
            }
            $settings[$key] = $this->validate($key, $value);
        }
        Database::run(
            'INSERT INTO user_settings (user_id, settings_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)',
            [$userId, json_encode($settings)]
        );
        return $settings;
    }

    private function validate(string $key, mixed $value): mixed
    {
        $rule = self::RULES[$key];
        switch ($rule[0]) {
            case 'enum':
                if (!in_array($value, $rule[1], true)) {
                    throw HttpException::badRequest("Invalid value for $key");
                }
                return $value;
            case 'bool':
                return (bool) $value;
            case 'labels':
                // [{key, name, color}], at most 20; an empty name means "use the translated default name".
                if (!is_array($value) || count($value) > 20) {
                    throw HttpException::badRequest('Invalid labels');
                }
                $out = [];
                $seen = [];
                foreach (array_values($value) as $l) {
                    $key = (string) ($l['key'] ?? '');
                    $color = (string) ($l['color'] ?? '');
                    $name = trim((string) ($l['name'] ?? ''));
                    if (!preg_match('/^\$?[A-Za-z0-9_.-]{1,40}$/', $key) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color) || mb_strlen($name) > 40 || isset($seen[strtolower($key)])) {
                        throw HttpException::badRequest('Invalid label');
                    }
                    $seen[strtolower($key)] = true;
                    $out[] = ['key' => $key, 'name' => $name, 'color' => strtoupper($color)];
                }
                return $out;
            case 'int':
                $int = filter_var($value, FILTER_VALIDATE_INT);
                if ($int === false || $int < $rule[1] || $int > $rule[2]) {
                    throw HttpException::badRequest("Invalid value for $key");
                }
                return $int;
        }
        return $value;
    }
}
