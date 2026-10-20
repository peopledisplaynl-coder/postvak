<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Repositories\AccountRepository;
use Postvak\Repositories\InboxTabSenders;

/** Moving a sender to another inbox tab ("mail from this sender goes to Promotions from now on"). */
final class InboxTabController
{
    public function __construct(
        private readonly InboxTabSenders $senders = new InboxTabSenders(),
        private readonly AccountRepository $accounts = new AccountRepository(),
    ) {}

    /** GET /inbox-tab-senders → [{account_id, sender, category}] */
    public function index(Request $r): void
    {
        Response::json($this->senders->forUser(Auth::requireUser()));
    }

    /** POST /accounts/{id}/inbox-tab-senders  {email, category} */
    public function store(Request $r): void
    {
        $account = $this->accounts->findOwned(Auth::requireUser(), $r->intParam('id'));
        $this->senders->set((int) $account['id'], $r->string('email'), $r->string('category'));
        Response::json(['email' => strtolower(trim($r->string('email'))), 'category' => $r->string('category')]);
    }

    /** DELETE /accounts/{id}/inbox-tab-senders?email= — back to sorting by the headers */
    public function destroy(Request $r): void
    {
        $account = $this->accounts->findOwned(Auth::requireUser(), $r->intParam('id'));
        $this->senders->remove((int) $account['id'], (string) $r->query('email', ''));
        Response::noContent();
    }
}
