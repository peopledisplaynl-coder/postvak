<?php
declare(strict_types=1);

namespace Postvak\Dav;

use Postvak\Database;
use Sabre\CalDAV\Backend\PDO;

/** sabre/dav's PDO backend on the dav_* tables (migration 009). */
final class CalendarBackend extends PDO
{
    public $calendarTableName = 'dav_calendars';
    public $calendarInstancesTableName = 'dav_calendarinstances';
    public $calendarObjectTableName = 'dav_calendarobjects';
    public $calendarChangesTableName = 'dav_calendarchanges';
    public $schedulingObjectTableName = 'dav_schedulingobjects';
    public $calendarSubscriptionsTableName = 'dav_calendarsubscriptions';

    public function __construct()
    {
        parent::__construct(Database::pdo());
    }
}
