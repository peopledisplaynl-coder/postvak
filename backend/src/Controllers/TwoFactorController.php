<?php
declare(strict_types=1);

namespace Postvak\Controllers;

use Postvak\Auth;
use Postvak\Database;
use Postvak\Http\Request;
use Postvak\Http\Response;
use Postvak\Security\TwoFactor;

/** Settings → Beveiliging: switch two-factor authentication on/off, recovery codes, devices. */
final class TwoFactorController
{
    public function __construct(private readonly TwoFactor $twoFactor = new TwoFactor()) {}

    /** GET /security */
    public function show(Request $r): void
    {
        Response::json($this->twoFactor->status(Auth::requireUser()));
    }

    /** POST /security/2fa/setup — new secret + otpauth link (for the QR code). */
    public function setup(Request $r): void
    {
        $userId = Auth::requireUser();
        $email = (string) (Database::one('SELECT login_email FROM users WHERE id = ?', [$userId])['login_email'] ?? '');
        Response::json($this->twoFactor->beginSetup($userId, $email));
    }

    /** POST /security/2fa/enable  {code} — returns the recovery codes once. */
    public function enable(Request $r): void
    {
        $userId = Auth::requireUser();
        $codes = $this->twoFactor->enable($userId, $r->string('code'));
        // This device was just used to set it up: no code needed here for 30 days.
        $this->twoFactor->trustThisDevice($userId, $r->header('User-Agent'));
        Response::json(['recovery_codes' => $codes, 'status' => $this->twoFactor->status($userId)]);
    }

    /** POST /security/2fa/disable  {code} */
    public function disable(Request $r): void
    {
        $userId = Auth::requireUser();
        $this->twoFactor->disable($userId, $r->string('code'));
        Response::json($this->twoFactor->status($userId));
    }

    /** POST /security/2fa/recovery  {code} — a new set of recovery codes. */
    public function recovery(Request $r): void
    {
        $userId = Auth::requireUser();
        Response::json(['recovery_codes' => $this->twoFactor->regenerateRecovery($userId, $r->string('code'))]);
    }

    /** POST /security/devices/forget — every device has to enter a code again. */
    public function forgetDevices(Request $r): void
    {
        $userId = Auth::requireUser();
        $this->twoFactor->forgetDevices($userId);
        Response::json($this->twoFactor->status($userId));
    }
}
