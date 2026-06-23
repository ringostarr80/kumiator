<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Services\User\Contracts\UserEmailChangerContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Räumt abgelaufene `pending_email*`-Datensätze (TTL 60 Min). Wird vom
 * Scheduler aufgerufen (Frequenz siehe `routes/console.php`). Jeder bereinigte
 * Datensatz erzeugt einen `email_change_cancelled`-Audit-Eintrag mit
 * `cancelled_via = 'ttl_expired'`, sodass die Lebenszyklus-Spur einer Anfrage
 * lückenlos im `auth`-Log liegt — auch wenn der User sie nicht aktiv abbricht.
 *
 * DSGVO-Hintergrund (Art. 5(1)(c)/(e)): Stale `pending_email`-Werte sind
 * personenbezogene Daten ohne weiteren Zweck nach Ablauf der Frist und
 * müssen gelöscht werden.
 */
#[Signature('user:cleanup-pending-email-changes')]
#[Description('Räumt abgelaufene Anfragen zur E-Mail-Adress-Änderung')]
class CleanupPendingEmailChanges extends Command
{
    public function __construct(private readonly UserEmailChangerContract $emailChanger)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->emailChanger->cancelExpired();

        $this->info(
            $count === 0
                ? __('commands.cleanup_pending_email_changes.none')
                : __('commands.cleanup_pending_email_changes.success', ['count' => $count]),
        );

        return self::SUCCESS;
    }
}
