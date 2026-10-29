<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Ohne diese Mail erführe das Mitglied erst bei einem eigenen Anmeldeversuch,
 * dass es freigeschaltet ist. Über die Queue, damit die Freischaltung nicht
 * davon abhängt, ob der Mailserver gerade antwortet.
 *
 * Der Hinweis auf „Passwort vergessen?“ gilt Konten, deren Inhaber den Link
 * aus der Registrierung nicht rechtzeitig eingelöst hat: Er verfällt, die
 * Freischaltung kann Tage später kommen.
 */
final class AccountApprovedNotification extends Notification implements ShouldQueue
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
     * Anrede ohne Namen: Er stammt aus der Registrierung, und die kann jeder mit
     * dieser Adresse abgeschickt haben. Das Festlegen des Passworts belegt nur
     * das Postfach, nicht den Namen, und ändern kann der Inhaber ihn erst nach
     * der Freischaltung.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('app.account_approved_subject'))
            ->greeting(__('app.mail_greeting_without_name'))
            ->line(__('app.account_approved_intro'))
            ->action(__('app.account_approved_action'), route('login'))
            ->line(__('app.account_approved_password_hint'))
            ->line(__('app.account_approved_ignore'));
    }
}
