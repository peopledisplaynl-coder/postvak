<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Database;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Mail\AutoReply;
use Postvak\Mail\MailProcessor;
use Postvak\Mail\RuleEngine;
use Postvak\Repositories\AccountRepository;
use Throwable;

/** Rules for new mail, out-of-office replies and reply templates. */
final class RuleController
{
    private const MAX_RULES = 100;
    private const MAX_TEMPLATES = 100;

    // ------------------------------------------------------------------- rules

    /** GET /rules */
    public function index(Request $r): void
    {
        Response::json(MailProcessor::rules(Auth::requireUser()));
    }

    /** POST /rules */
    public function store(Request $r): void
    {
        $userId = Auth::requireUser();
        $rule = RuleEngine::validate($r->body());
        $this->assertAccount($userId, $rule['account_id']);
        if ((int) (Database::one('SELECT COUNT(*) n FROM mail_rules WHERE user_id = ?', [$userId])['n'] ?? 0) >= self::MAX_RULES) {
            throw HttpException::badRequest('Too many rules (max ' . self::MAX_RULES . ')');
        }
        $order = (int) (Database::one('SELECT MAX(sort_order) m FROM mail_rules WHERE user_id = ?', [$userId])['m'] ?? 0) + 1;
        Database::run(
            'INSERT INTO mail_rules (user_id, account_id, name, enabled, match_all, conditions_json, actions_json, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $rule['account_id'], $rule['name'], (int) $rule['enabled'], (int) $rule['match_all'], json_encode($rule['conditions'], JSON_UNESCAPED_UNICODE), json_encode($rule['actions'], JSON_UNESCAPED_UNICODE), $order]
        );
        Response::json($this->find($userId, Database::lastId()), 201);
    }

    /** PUT /rules/{id} — the whole rule; PATCH with only {enabled} switches it on or off. */
    public function update(Request $r): void
    {
        $userId = Auth::requireUser();
        $id = $r->intParam('id');
        $this->find($userId, $id);
        $body = $r->body();
        if (array_keys($body) === ['enabled']) {
            Database::run('UPDATE mail_rules SET enabled = ? WHERE id = ? AND user_id = ?', [(int) !empty($body['enabled']), $id, $userId]);
            Response::json($this->find($userId, $id));
        }
        $rule = RuleEngine::validate($body);
        $this->assertAccount($userId, $rule['account_id']);
        Database::run(
            'UPDATE mail_rules SET account_id = ?, name = ?, enabled = ?, match_all = ?, conditions_json = ?, actions_json = ? WHERE id = ? AND user_id = ?',
            [$rule['account_id'], $rule['name'], (int) $rule['enabled'], (int) $rule['match_all'], json_encode($rule['conditions'], JSON_UNESCAPED_UNICODE), json_encode($rule['actions'], JSON_UNESCAPED_UNICODE), $id, $userId]
        );
        Response::json($this->find($userId, $id));
    }

    /** DELETE /rules/{id} */
    public function destroy(Request $r): void
    {
        $userId = Auth::requireUser();
        $this->find($userId, $r->intParam('id'));
        Database::run('DELETE FROM mail_rules WHERE id = ? AND user_id = ?', [$r->intParam('id'), $userId]);
        Response::noContent();
    }

    /** POST /rules/order {ids: [..]} — the order in which rules are tried. */
    public function order(Request $r): void
    {
        $userId = Auth::requireUser();
        foreach (array_values((array) $r->input('ids', [])) as $i => $id) {
            Database::run('UPDATE mail_rules SET sort_order = ? WHERE id = ? AND user_id = ?', [$i, (int) $id, $userId]);
        }
        Response::json(MailProcessor::rules($userId));
    }

    /** POST /rules/apply {rule_id?} — run on the last 500 messages in the inbox now. */
    public function apply(Request $r): void
    {
        $userId = Auth::requireUser();
        @set_time_limit(120);
        $ruleId = $r->input('rule_id');
        try {
            Response::json((new MailProcessor())->applyNow($userId, is_numeric($ruleId) ? (int) $ruleId : null));
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HttpException::upstream('The mail server could not apply the rules: ' . $e->getMessage());
        }
    }

    private function find(int $userId, int $id): array
    {
        foreach (MailProcessor::rules($userId) as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }
        throw HttpException::notFound('Rule not found');
    }

    private function assertAccount(int $userId, ?int $accountId): void
    {
        if ($accountId !== null) {
            (new AccountRepository())->findOwned($userId, $accountId); // 404 when not the user's
        }
    }

    // ----------------------------------------------------------- out of office

    /** GET /autoreplies — settings per account of the user. */
    public function autoreplies(Request $r): void
    {
        $userId = Auth::requireUser();
        $out = [];
        foreach ((new AccountRepository())->forUser($userId) as $a) {
            $s = AutoReply::settings((int) $a['id']);
            $out[] = ['account_id' => (int) $a['id'], 'email' => $a['email'], 'active' => AutoReply::active($s)] + $s;
        }
        Response::json($out);
    }

    /** PUT /autoreplies/{accountId} {enabled, starts_on?, ends_on?, subject, body, contacts_only} */
    public function saveAutoreply(Request $r): void
    {
        $userId = Auth::requireUser();
        $accountId = $r->intParam('id');
        (new AccountRepository())->findOwned($userId, $accountId);
        $date = static function ($v): ?string {
            $v = trim((string) ($v ?? ''));
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
                throw HttpException::badRequest('Invalid date');
            }
            return $v;
        };
        $starts = $date($r->input('starts_on'));
        $ends = $date($r->input('ends_on'));
        if ($starts && $ends && $ends < $starts) {
            throw HttpException::badRequest('The end date is before the start date');
        }
        $body = trim((string) $r->input('body', ''));
        $enabled = (bool) $r->input('enabled', false);
        if ($enabled && $body === '') {
            throw HttpException::badRequest('Write the text of the reply first');
        }
        if (mb_strlen($body) > 5000 || mb_strlen((string) $r->input('subject', '')) > 255) {
            throw HttpException::badRequest('The text is too long');
        }
        Database::run(
            'INSERT INTO autoreplies (account_id, enabled, starts_on, ends_on, subject, body, contacts_only) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), starts_on = VALUES(starts_on), ends_on = VALUES(ends_on),
               subject = VALUES(subject), body = VALUES(body), contacts_only = VALUES(contacts_only)',
            [$accountId, (int) $enabled, $starts, $ends, trim((string) $r->input('subject', '')), $body, (int) (bool) $r->input('contacts_only', false)]
        );
        if (!$enabled) {
            Database::run('DELETE FROM autoreply_log WHERE account_id = ?', [$accountId]); // next time everyone gets a reply again
        }
        $s = AutoReply::settings($accountId);
        Response::json(['account_id' => $accountId, 'active' => AutoReply::active($s)] + $s);
    }

    // --------------------------------------------------------------- templates

    /** GET /templates */
    public function templates(Request $r): void
    {
        $userId = Auth::requireUser();
        Response::json(array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'subject' => $t['subject'], 'html' => $t['html']],
            Database::all('SELECT * FROM mail_templates WHERE user_id = ? ORDER BY name', [$userId])));
    }

    /** POST /templates {name, subject?, html} */
    public function storeTemplate(Request $r): void
    {
        $userId = Auth::requireUser();
        [$name, $subject, $html] = $this->templateInput($r);
        if ((int) (Database::one('SELECT COUNT(*) n FROM mail_templates WHERE user_id = ?', [$userId])['n'] ?? 0) >= self::MAX_TEMPLATES) {
            throw HttpException::badRequest('Too many templates (max ' . self::MAX_TEMPLATES . ')');
        }
        Database::run('INSERT INTO mail_templates (user_id, name, subject, html) VALUES (?, ?, ?, ?)', [$userId, $name, $subject, $html]);
        Response::json(['id' => Database::lastId(), 'name' => $name, 'subject' => $subject, 'html' => $html], 201);
    }

    /** PUT /templates/{id} */
    public function updateTemplate(Request $r): void
    {
        $userId = Auth::requireUser();
        [$name, $subject, $html] = $this->templateInput($r);
        $n = Database::run('UPDATE mail_templates SET name = ?, subject = ?, html = ? WHERE id = ? AND user_id = ?', [$name, $subject, $html, $r->intParam('id'), $userId])->rowCount();
        if ($n === 0 && !Database::one('SELECT id FROM mail_templates WHERE id = ? AND user_id = ?', [$r->intParam('id'), $userId])) {
            throw HttpException::notFound('Template not found');
        }
        Response::json(['id' => $r->intParam('id'), 'name' => $name, 'subject' => $subject, 'html' => $html]);
    }

    /** DELETE /templates/{id} */
    public function destroyTemplate(Request $r): void
    {
        Database::run('DELETE FROM mail_templates WHERE id = ? AND user_id = ?', [$r->intParam('id'), Auth::requireUser()]);
        Response::noContent();
    }

    /** @return array{0:string, 1:?string, 2:string} */
    private function templateInput(Request $r): array
    {
        $name = trim((string) $r->input('name', ''));
        $html = (string) $r->input('html', '');
        $subject = trim((string) $r->input('subject', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw HttpException::badRequest('A template needs a name (max 100 characters)');
        }
        if (trim(strip_tags($html)) === '' && !str_contains($html, '<img')) {
            throw HttpException::badRequest('The template is empty');
        }
        if (strlen($html) > 200_000) {
            throw HttpException::badRequest('The template is too large');
        }
        // The composer's editor only keeps its own formatting; this just removes anything active.
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        return [$name, $subject !== '' ? mb_substr($subject, 0, 255) : null, $html];
    }
}
