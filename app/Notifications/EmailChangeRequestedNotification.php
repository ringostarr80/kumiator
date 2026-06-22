<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Geht an die AKTUELLE (alte) Adresse `email`. Sicherheits-Hinweis-Mail mit
 * Cancel-Link für den Fall, dass der User die Änderung NICHT selbst angestoßen
 * hat (Hijack-Schutz). Wird IMMER versendet, auch wenn der User mit großer
 * Wahrscheinlichkeit der echte Urheber ist — die Hinweisfunktion ist der
 * gesamte Sinn der Mail.
 *
 * Routing: `$user->notify(...)` reicht — `pending_email` wurde noch nicht in
 * die `email`-Spalte übernommen, also zielt die Default-Route von Notifiable
 * korrekt auf die alte Adresse.
 *
 * `ShouldQueueAfterCommit`: wie die Confirm-Mail erst nach Commit der
 * Profil-Update-Transaktion versenden — ein Rollback darf keine Hinweis-Mail
 * für eine nicht persistierte Änderung auslösen.
 */
final class EmailChangeRequestedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly string $plainToken, private readonly string $pendingEmail)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $cancelUrl = route('email.change.cancel', ['token' => $this->plainToken]);

        return (new MailMessage())
            ->subject(__('app.email_change_requested_subject'))
            ->greeting(__('app.email_change_requested_greeting', ['name' => $notifiable->name]))
            ->line(__('app.email_change_requested_intro', ['email' => $this->pendingEmail]))
            ->line(__('app.email_change_requested_warning'))
            ->action(__('app.email_change_requested_cancel_action'), $cancelUrl)
            ->line(__('app.email_change_requested_ttl'))
            ->line(__('app.email_change_requested_self_hint'));
    }
}
