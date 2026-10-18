<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Calendar\CalendarService;
use Postvak\Config;
use Postvak\Migrator;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;

/**
 * CalDAV and CardDAV server for phones and desktop apps (calendars, tasks, contacts), at <app_url>/dav/.
 * Login: Postvak address + an app password from Settings → Calendar.
 */
final class Server
{
    public static function baseUri(): string
    {
        $path = rtrim((string) parse_url((string) Config::get('app_url', ''), PHP_URL_PATH), '/');
        return $path . '/dav/';
    }

    public static function run(string $ip): void
    {
        Migrator::ensureCurrent(POSTVAK_VERSION);

        $principals = new PrincipalBackend();
        $calendars = new CalendarBackend();
        $auth = new AuthBackend($ip);

        $server = new DAV\Server([
            new CalDAV\Principal\Collection($principals),
            new CalDAV\CalendarRoot($principals, $calendars),
            new CardDAV\AddressBookRoot($principals, new AddressBookBackend()),
        ]);
        $server->setBaseUri(self::baseUri());
        $server->debugExceptions = (bool) Config::get('debug', false);

        $server->addPlugin(new DAV\Auth\Plugin($auth));
        $acl = new DAVACL\Plugin();
        $acl->hideNodesFromListings = true;
        $acl->allowUnauthenticatedAccess = false;
        $server->addPlugin($acl);
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CalDAV\ICSExportPlugin());
        $server->addPlugin(new CardDAV\Plugin());
        $server->addPlugin(new DAV\Sync\Plugin());

        // After authentication: make sure the user has the default calendar and task list.
        $server->on('beforeMethod:*', static function () use ($auth): void {
            if ($auth->userId !== null) {
                (new CalendarService())->ensureDefaults($auth->userId);
            }
        }, 20);

        $server->start();
    }
}
