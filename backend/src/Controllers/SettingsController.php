<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Repositories\SettingsRepository;

final class SettingsController
{
    public function __construct(private readonly SettingsRepository $settings = new SettingsRepository()) {}

    /** GET /settings */
    public function show(Request $r): void
    {
        Response::json($this->settings->get(Auth::requireUser()));
    }

    /** PATCH /settings  {swipe_left: "archive", ...} */
    public function update(Request $r): void
    {
        Response::json($this->settings->update(Auth::requireUser(), $r->body()));
    }
}
