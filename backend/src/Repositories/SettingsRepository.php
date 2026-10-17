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
    public const SWIPE_ACTIONS = ['archive', 'trash', 'read', 'flag', 'none'];

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
    ];

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
    ];

    public function get(int $userId): array
    {
        $row = Database::one('SELECT settings_json FROM user_settings WHERE user_id = ?', [$userId]);
        $stored = $row ? (json_decode($row['settings_json'], true) ?: []) : [];
        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
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
