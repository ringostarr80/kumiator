<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Geht an die ANGEFRAGTE (neue) Adresse `pending_email`. Klick auf den
 * Confirm-Link tauscht serverseitig `email` ← `pending_email`.
 *
 * Routing: per `Notification::route('mail', $pendingEmail)->notify(...)`,
 * weil `Notifiable::routeNotificationForMail()` standardmäßig auf
 * `$user->email` (die ALTE Adresse) zielen würde. Der User wird der
 * Notification trotzdem als Konstruktor-Argument mitgegeben, damit die
 * Anrede personalisiert werden kann.
 *
 * `ShouldQueueAfterCommit`: Der Versand wird über `Notification::route()` aus
 * `UserEmailChanger::requestChange()` ausgelöst, das innerhalb der
 * Profil-Update-Transaktion läuft. Erst nach deren Commit darf die Mail raus —
 * sonst verschickt ein zurückgerollter Antrag einen Confirm-Link auf einen
 * `pending_email`-Zustand, der nie persistiert wurde. Der Klartext-Token landet
 * dabei zwischenzeitlich im Queue-Payload (Redis/DB); das nehmen wir in Kauf,
 * weil die TTL (60 Min) kurz ist und ein Queue-Datenleck im Projekt-Threat-
 * Model bereits andere kompromittierte Pfade implizieren würde.
 */
final class VerifyEmailChangeNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly User $user,
        private readonly string $plainToken,
        private readonly string $pendingEmail,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        $url = route('email.change.confirm', ['token' => $this->plainToken]);

        return (new MailMessage())
            ->subject(__('app.email_change_verify_subject'))
            ->greeting(__('app.email_change_verify_greeting', ['name' => $this->user->name]))
            ->line(__('app.email_change_verify_intro', ['email' => $this->pendingEmail]))
            ->action(__('app.email_change_verify_action'), $url)
            ->line(__('app.email_change_verify_ttl'))
            ->line(__('app.email_change_verify_ignore'));
    }
}
