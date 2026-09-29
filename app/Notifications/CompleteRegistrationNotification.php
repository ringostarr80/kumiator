<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Ein unbestätigtes Konto kann jemand anderes mit fremdem Passwort angelegt
 * haben. Statt eines Bestätigungslinks, der es samt diesem Passwort bestätigte,
 * bekommt das Postfach einen Reset-Link; der Reset bestätigt die Adresse mit.
 *
 * Über die Queue wie die übrigen Mails der Registrierung, und den Token erst
 * im Worker: Der Broker hasht ihn mit bcrypt, diesen Lauf bezahlte die
 * Registrierung sonst nur für unbestätigte Adressen. Aus demselben Grund
 * trägt der Job keinen Token und braucht keine Verschlüsselung.
 *
 * Wie jede Reset-Anforderung entwertet der Token offene Links, auch die eines
 * früheren Versuchs dieses Jobs. Das nimmt dem Inhaber nichts: Der neue Link
 * geht ins selbe Postfach.
 */
final class CompleteRegistrationNotification extends Notification implements ShouldQueue
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
     * Anrede ohne Namen: Den eines unbestätigten Kontos kann jeder eingetragen
     * haben.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $token = Password::createToken($notifiable);

        return (new MailMessage())
            ->subject(__('app.complete_registration_subject'))
            ->greeting(__('app.mail_greeting_without_name'))
            ->line(__('app.complete_registration_intro'))
            ->action(
                __('app.complete_registration_action'),
                route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]),
            )
            ->line(__('app.complete_registration_ignore'));
    }
}
