<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Über die Queue wie die Mail an ein neues Konto: Ein synchroner
 * Versand dauerte so lange, wie der Mailserver braucht, und könnte die Timebox
 * der Registrierung sprengen.
 */
final class AccountAlreadyExistsNotification extends Notification implements ShouldQueue
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
     * Anrede ohne Namen: Auch ein bestätigtes Konto kann noch den Namen einer
     * fremden Registrierung tragen, und diese Mail löst jeder aus, der die
     * Adresse erneut registriert.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('app.account_exists_subject'))
            ->greeting(__('app.mail_greeting_without_name'))
            ->line(__('app.account_exists_intro'))
            ->action(__('app.account_exists_action'), route('login'))
            ->line(__('app.account_exists_ignore'));
    }
}
