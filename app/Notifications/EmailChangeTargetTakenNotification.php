<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Über die Queue wie die Bestätigungsmail an eine freie Adresse: Ginge eine der
 * beiden synchron raus, verriete die Antwortzeit des Profils, ob die Adresse
 * vergeben ist. Erst nach dem Commit, weil der Antrag in der Transaktion des
 * Profil-Updates läuft und ein zurückgerollter Antrag niemanden anschreiben darf.
 */
final class EmailChangeTargetTakenNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    /**
     * Anrede ohne Namen: Der Inhaber kann ein unbestätigtes Konto sein, dessen
     * Namen jeder eingetragen haben kann.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('app.email_change_target_taken_subject'))
            ->greeting(__('app.mail_greeting_without_name'))
            ->line(__('app.email_change_target_taken_intro'))
            ->line(__('app.email_change_target_taken_hint'));
    }
}
