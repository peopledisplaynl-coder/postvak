<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use DateTimeImmutable;
use Postvak\Auth;
use Postvak\Calendar\CalendarService;
use Postvak\Calendar\FeedService;
use Postvak\Calendar\ShareService;
use Postvak\Config;
use Postvak\Dav\AppPasswords;
use Postvak\Dav\Server;
use Postvak\Http\HttpException;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Throwable;

/** Calendar, tasks and the app passwords for syncing them with phones (CalDAV). */
final class CalendarController
{
    public function __construct(
        private readonly CalendarService $calendar = new CalendarService(),
        private readonly FeedService $feeds = new FeedService(),
        private readonly ShareService $shares = new ShareService(),
    ) {}

    private function tz(Request $r): \DateTimeZone
    {
        return CalendarService::tz((string) ($r->query('tz') ?? $r->input('timezone') ?? 'UTC'));
    }

    // -------------------------------------------------------------- calendars

    /** GET /calendars — with `feed` (calendar from elsewhere) and `share_url` (secret .ics link) where set. */
    public function calendars(Request $r): void
    {
        $userId = Auth::requireUser();
        $feeds = $this->feeds->byInstance($userId);
        $links = $this->shares->linksFor($userId);
        Response::json(array_map(static fn ($c) => $c + [
            'feed'      => $feeds[$c['id']] ?? null,
            'share_url' => $links[$c['id']] ?? null,
        ], $this->calendar->calendars($userId)));
    }

    /** POST /calendar-feeds  {url, name?, color?} — subscribe to an iCal address (read-only). */
    public function addFeed(Request $r): void
    {
        $userId = Auth::requireUser();
        $name = $r->input('name');
        $color = $r->input('color');
        $feed = $this->feeds->add($userId, $r->string('url'), is_string($name) ? $name : '', is_string($color) && $color !== '' ? $color : '#0E7490');
        Response::json($feed, 201);
    }

    /** POST /calendar-feeds/{id}/refresh */
    public function refreshFeed(Request $r): void
    {
        $userId = Auth::requireUser();
        try {
            Response::json($this->feeds->refreshOne($userId, $r->intParam('id')));
        } catch (\RuntimeException $e) {
            throw HttpException::badRequest($e->getMessage());
        }
    }

    /** DELETE /calendar-feeds/{id} */
    public function deleteFeed(Request $r): void
    {
        $this->feeds->remove(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }

    /** POST /calendars/{id}/share — new secret link (replaces an old one). */
    public function share(Request $r): void
    {
        Response::json(['share_url' => $this->shares->create(Auth::requireUser(), $r->intParam('id'))]);
    }

    /** DELETE /calendars/{id}/share */
    public function unshare(Request $r): void
    {
        $this->shares->revoke(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }

    /** GET /ical/{token}.ics — public, the token is the password. */
    public function ical(Request $r): void
    {
        $token = preg_replace('/\.ics$/', '', (string) $r->param('token'));
        $ics = $this->shares->export($token);
        if ($ics === null) {
            Response::json(['error' => 'Not found'], 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="calendar.ics"');
        header('Cache-Control: private, max-age=300');
        header('X-Robots-Tag: noindex');
        echo $ics;
        exit;
    }

    /** POST /calendars  {name, color, type: events|tasks} */
    public function createCalendar(Request $r): void
    {
        $type = $r->string('type') === 'tasks' ? 'tasks' : 'events';
        Response::json($this->calendar->createCalendar(Auth::requireUser(), $r->string('name'), $r->string('color'), $type), 201);
    }

    /** PATCH /calendars/{id}  {name?, color?} */
    public function updateCalendar(Request $r): void
    {
        Response::json($this->calendar->updateCalendar(Auth::requireUser(), $r->intParam('id'), $r->body()));
    }

    /** DELETE /calendars/{id} (a calendar from elsewhere: also stops fetching it) */
    public function deleteCalendar(Request $r): void
    {
        $userId = Auth::requireUser();
        if (!$this->feeds->removeByInstance($userId, $r->intParam('id'))) {
            $this->calendar->deleteCalendar($userId, $r->intParam('id'));
        }
        Response::noContent();
    }

    // ----------------------------------------------------------------- events

    /** GET /events?start=2026-10-01&end=2026-11-01&tz=Europe/Amsterdam[&calendars=1,2] */
    public function events(Request $r): void
    {
        $userId = Auth::requireUser();
        $tz = $this->tz($r);
        try {
            $start = new DateTimeImmutable((string) $r->query('start'), $tz);
            $end = new DateTimeImmutable((string) $r->query('end'), $tz);
        } catch (Throwable) {
            throw HttpException::badRequest('Invalid period');
        }
        if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 400 * 86400) {
            throw HttpException::badRequest('Invalid period');
        }
        $ids = $r->query('calendars') ? array_map('intval', explode(',', (string) $r->query('calendars'))) : null;
        Response::json($this->calendar->events($userId, $start, $end, $tz, $ids));
    }

    /** GET /events/{id}?tz= — the event (series) with editor fields. */
    public function event(Request $r): void
    {
        Response::json($this->calendar->event(Auth::requireUser(), $r->intParam('id'), $this->tz($r)));
    }

    /** POST /events */
    public function createEvent(Request $r): void
    {
        Response::json($this->calendar->saveEvent(Auth::requireUser(), null, $r->body()), 201);
    }

    /** PUT /events/{id} */
    public function updateEvent(Request $r): void
    {
        Response::json($this->calendar->saveEvent(Auth::requireUser(), $r->intParam('id'), $r->body()));
    }

    /** DELETE /events/{id}[?occurrence=timestamp] */
    public function deleteEvent(Request $r): void
    {
        $occurrence = $r->query('occurrence');
        $this->calendar->deleteEvent(Auth::requireUser(), $r->intParam('id'), $occurrence !== null && $occurrence !== '' ? (int) $occurrence : null);
        Response::noContent();
    }

    // ------------------------------------------------------------------ tasks

    /** GET /tasks?tz=&completed=0 */
    public function tasks(Request $r): void
    {
        Response::json($this->calendar->tasks(Auth::requireUser(), $this->tz($r), (string) $r->query('completed', '1') !== '0'));
    }

    /** POST /tasks */
    public function createTask(Request $r): void
    {
        Response::json($this->calendar->saveTask(Auth::requireUser(), null, $r->body()), 201);
    }

    /** PATCH /tasks/{id} — only the fields sent change. */
    public function updateTask(Request $r): void
    {
        Response::json($this->calendar->saveTask(Auth::requireUser(), $r->intParam('id'), $r->body()));
    }

    /** DELETE /tasks/{id} */
    public function deleteTask(Request $r): void
    {
        $this->calendar->deleteTask(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }

    // ------------------------------------------------------- phones (CalDAV)

    /** GET /dav — what a calendar app needs: server address, user name, app passwords. */
    public function davInfo(Request $r): void
    {
        $userId = Auth::requireUser();
        $user = \Postvak\Database::one('SELECT login_email FROM users WHERE id = ?', [$userId]);
        $base = rtrim((string) Config::get('app_url', ''), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);
        Response::json([
            'url'       => $base . '/dav/',
            'server'    => $host,
            'username'  => $user['login_email'] ?? '',
            'base_path' => Server::baseUri(),
            'passwords' => AppPasswords::forUser($userId),
        ]);
    }

    /** POST /dav/passwords  {name} — the password is in this response only. */
    public function createAppPassword(Request $r): void
    {
        $userId = Auth::requireUser();
        if (count(AppPasswords::forUser($userId)) >= 20) {
            throw HttpException::badRequest('Too many app passwords; remove one first');
        }
        Response::json(AppPasswords::create($userId, $r->string('name')), 201);
    }

    /** DELETE /dav/passwords/{id} */
    public function deleteAppPassword(Request $r): void
    {
        AppPasswords::delete(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }
}
