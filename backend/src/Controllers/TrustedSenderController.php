<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Repositories\TrustedSenderRepository;

/** The user's list of safe senders (remote images always shown). */
final class TrustedSenderController
{
    public function __construct(private readonly TrustedSenderRepository $repo = new TrustedSenderRepository()) {}

    /** GET /trusted-senders */
    public function index(Request $r): void
    {
        Response::json($this->repo->forUser(Auth::requireUser()));
    }

    /** POST /trusted-senders  {kind: "email"|"domain", value: "x@y.nl" | "y.nl"} */
    public function store(Request $r): void
    {
        Response::json($this->repo->add(Auth::requireUser(), $r->string('kind'), $r->string('value')), 201);
    }

    /** DELETE /trusted-senders/{id} */
    public function destroy(Request $r): void
    {
        $this->repo->remove(Auth::requireUser(), $r->intParam('id'));
        Response::noContent();
    }
}
