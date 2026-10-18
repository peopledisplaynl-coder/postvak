<?php
declare(strict_types=1);

namespace Postvak\Mail;

use Postvak\Http\HttpException;

/**
 * Rules for new mail: "if the sender contains X, move to folder Y". Pure logic (no IMAP), so it can be
 * tested on its own; Mail\MailProcessor applies the result.
 *
 * A rule: {conditions: [{field, op, value}], match_all, actions: {move?, read?, flag?, label?, trash?}}.
 */
final class RuleEngine
{
    public const FIELDS = ['from', 'to', 'subject', 'any'];
    public const OPS = ['contains', 'not_contains', 'is', 'starts_with', 'ends_with'];

    /** Validate and normalise input from the settings page. */
    public static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::badRequest('A rule needs a name (max 100 characters)');
        }
        $conditions = [];
        foreach ((array) ($in['conditions'] ?? []) as $c) {
            $field = (string) ($c['field'] ?? '');
            $op = (string) ($c['op'] ?? '');
            $value = trim((string) ($c['value'] ?? ''));
            if (!in_array($field, self::FIELDS, true) || !in_array($op, self::OPS, true)) {
                throw HttpException::badRequest('Unknown condition');
            }
            if ($value === '' || mb_strlen($value) > 255) {
                throw HttpException::badRequest('Every condition needs a value (max 255 characters)');
            }
            $conditions[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }
        if ($conditions === [] || count($conditions) > 10) {
            throw HttpException::badRequest('A rule needs 1 to 10 conditions');
        }
        $a = (array) ($in['actions'] ?? []);
        $actions = [
            'move'  => isset($a['move']) && is_string($a['move']) && trim($a['move']) !== '' ? mb_substr(trim($a['move']), 0, 500) : null,
            'trash' => !empty($a['trash']),
            'read'  => !empty($a['read']),
            'flag'  => !empty($a['flag']),
            'label' => isset($a['label']) && is_string($a['label']) && preg_match('/^\$?[A-Za-z0-9_.-]{1,40}$/', $a['label']) ? $a['label'] : null,
        ];
        if ($actions['trash']) {
            $actions['move'] = null;
        }
        if (!$actions['move'] && !$actions['trash'] && !$actions['read'] && !$actions['flag'] && !$actions['label']) {
            throw HttpException::badRequest('Choose at least one action');
        }
        $accountId = isset($in['account_id']) && $in['account_id'] !== null && $in['account_id'] !== '' ? (int) $in['account_id'] : null;
        if ($actions['move'] !== null && $accountId === null) {
            throw HttpException::badRequest('Moving to a folder needs one account (folders differ per account)');
        }
        return [
            'name'       => $name,
            'account_id' => $accountId,
            'enabled'    => !array_key_exists('enabled', $in) || !empty($in['enabled']),
            'match_all'  => !array_key_exists('match_all', $in) || !empty($in['match_all']),
            'conditions' => $conditions,
            'actions'    => $actions,
        ];
    }

    /** Does a message (from ImapService::arrivals) match the rule? */
    public static function matches(array $rule, array $msg): bool
    {
        $results = array_map(static fn ($c) => self::test($c, $msg), $rule['conditions']);
        if ($results === []) {
            return false;
        }
        return $rule['match_all'] ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    /**
     * What happens to each message: the flags of all matching rules; the first move or trash wins and stops.
     * @param array<int, array> $rules in order, already filtered for the account
     * @return array<int, array{read:bool, flag:bool, labels:string[], move:?string, trash:bool, rules:int[]}> by uid (only matched ones)
     */
    public static function plan(array $rules, array $messages): array
    {
        $plan = [];
        foreach ($messages as $msg) {
            $p = ['read' => false, 'flag' => false, 'labels' => [], 'move' => null, 'trash' => false, 'rules' => []];
            foreach ($rules as $rule) {
                if (!$rule['enabled'] || !self::matches($rule, $msg)) {
                    continue;
                }
                $p['rules'][] = (int) $rule['id'];
                $p['read'] = $p['read'] || $rule['actions']['read'];
                $p['flag'] = $p['flag'] || $rule['actions']['flag'];
                if (!empty($rule['actions']['label']) && !in_array($rule['actions']['label'], $p['labels'], true)) {
                    $p['labels'][] = $rule['actions']['label'];
                }
                if ($rule['actions']['trash']) {
                    $p['trash'] = true;
                    break;
                }
                if ($rule['actions']['move']) {
                    $p['move'] = $rule['actions']['move'];
                    break;
                }
            }
            if ($p['rules'] !== []) {
                $plan[(int) $msg['uid']] = $p;
            }
        }
        return $plan;
    }

    private static function test(array $c, array $msg): bool
    {
        $needle = mb_strtolower($c['value']);
        $values = array_map('mb_strtolower', self::values($c['field'], $msg));
        $hit = false;
        foreach ($values as $v) {
            $hit = match ($c['op']) {
                'contains', 'not_contains' => str_contains($v, $needle),
                'is'          => $v === $needle,
                'starts_with' => str_starts_with($v, $needle),
                'ends_with'   => str_ends_with($v, $needle),
                default       => false,
            };
            if ($hit) {
                break;
            }
        }
        return $c['op'] === 'not_contains' ? !$hit : $hit;
    }

    /** @return string[] the texts a condition looks at */
    private static function values(string $field, array $msg): array
    {
        $people = static function (array $list): array {
            $out = [];
            foreach ($list as $a) {
                if (!empty($a['email'])) {
                    $out[] = (string) $a['email'];
                }
                if (!empty($a['name'])) {
                    $out[] = (string) $a['name'];
                    if (!empty($a['email'])) {
                        $out[] = $a['name'] . ' <' . $a['email'] . '>';
                    }
                }
            }
            return $out;
        };
        $from = $people($msg['from'] ? [$msg['from']] : []);
        $to = $people([...($msg['to'] ?? []), ...($msg['cc'] ?? [])]);
        $subject = [(string) ($msg['subject'] ?? '')];
        return match ($field) {
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            default   => [...$from, ...$to, ...$subject],
        };
    }
}
