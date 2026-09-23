<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PasskeyChange;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Wer das Passwort hat, kann einen eigenen Passkey ablegen — einen
 * passwortlosen Zugang, den kein Passwortwechsel mehr schließt. Vom Konto aus
 * ist das unsichtbar, der Inhaber liest das Aktivitätslog nicht; die Mail ist
 * der einzige Kanal, auf dem er es erfährt. Sie geht deshalb immer raus, auch
 * wenn er es aller Wahrscheinlichkeit nach selbst war.
 *
 * Kein Widerrufs-Link: Die Änderung ist vollzogen und im Profil rückgängig zu
 * machen. Ein Link im Postfach, der Passkeys entfernt, wäre ein zweiter Hebel
 * für jeden, der das Postfach hat.
 *
 * `ShouldQueueAfterCommit` wie bei den Mails zum E-Mail-Wechsel: Läuft der
 * Versand innerhalb einer Transaktion, darf ein Rollback keine Mail über eine
 * Änderung auslösen, die nicht stattfand.
 */
final class PasskeyChangedNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(private readonly PasskeyChange $change, private readonly string $passkeyName)
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
        $subject = match ($this->change) {
            PasskeyChange::ADDED => __('app.passkey_added_subject'),
            PasskeyChange::REMOVED => __('app.passkey_removed_subject'),
        };

        $intro = match ($this->change) {
            PasskeyChange::ADDED => __('app.passkey_added_intro', ['passkey' => $this->passkeyName]),
            PasskeyChange::REMOVED => __('app.passkey_removed_intro', ['passkey' => $this->passkeyName]),
        };

        return (new MailMessage())
            ->subject($subject)
            ->greeting(__('app.passkey_changed_greeting', ['name' => $notifiable->name]))
            ->line($intro)
            ->line(__('app.passkey_changed_warning'))
            ->action(__('app.passkey_changed_action'), route('profile.show'))
            ->line(__('app.passkey_changed_self_hint'));
    }
}
