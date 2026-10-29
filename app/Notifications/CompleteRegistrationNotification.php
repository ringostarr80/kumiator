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
 * Wer eine Registrierung abschickt, muss nicht der Inhaber der Adresse sein.
 * Das Postfach bekommt deshalb einen Reset-Link, und der Reset bestätigt die
 * Adresse mit. Ein Bestätigungslink ließe ein neues Konto ohne Passwort, das
 * jemand kennt. Und ein bestehendes, unbestätigtes Konto kann eines tragen,
 * das ein anderer festgelegt hat, etwa auf der Konsole; ein Bestätigungslink
 * bestätigte die Adresse samt diesem Passwort.
 *
 * Über die Queue wie die übrigen Mails der Registrierung, und den Token erst
 * im Worker: Der Broker hasht ihn mit bcrypt, diesen Lauf bezahlte die
 * Registrierung sonst nur für freie und unbestätigte Adressen. Aus demselben
 * Grund trägt der Job keinen Token und braucht keine Verschlüsselung.
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
