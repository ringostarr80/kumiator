<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\PasskeyChange;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Notifications\PasskeyChangedNotification;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use App\Services\Auth\Contracts\OtherSessionRevokerContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Facades\Activity;

/**
 * Hält die Zusage, dass jedem Konto ein Anmeldeweg bleibt.
 *
 * Beide Wege dorthin lesen, was der jeweils andere schreibt: Das Abschalten
 * fragt nach Passkeys und setzt `password_login_disabled_at`, das Löschen liest
 * dieselbe Spalte und nimmt einen Passkey. Ohne Klammer sieht jeder den Stand von vor dem anderen,
 * und am Ende steht ein Konto ohne Passkey und ohne Passwort-Login — offen nur
 * noch über die Konsole. Die Klammer ist die gesperrte Nutzerzeile, die beide
 * nehmen, bevor sie lesen.
 */
final class LoginMethodChanger implements LoginMethodChangerContract
{
    public function __construct(
        private readonly PasskeyCredentialRepositoryContract $passkeys,
        private readonly OtherSessionRevokerContract $sessionRevoker,
        private readonly UserSessionTerminatorContract $sessionTerminator,
    ) {
    }

    public function disablePasswordLogin(User $user): bool
    {
        $account = DB::transaction(function () use ($user): ?User {
            $account = $this->lockAccount($user->id);

            // Entschieden wird auf der gesperrten Zeile, nicht auf der Instanz des
            // Aufrufers: Zwei Aufrufer, die beide den Schalter noch offen sahen,
            // verschöben sonst den Stempel, seit dem das Konto ohne Passwort
            // auskommt, und legten ein zweites Ereignis für einen Vorgang an, der
            // nur einmal stattfand.
            if ($account->isPasswordLoginDisabled()) {
                return $account;
            }

            if (!$account->hasPasskey()) {
                return null;
            }

            // Direkt zugewiesen statt über `update()`: Die Spalte steht bewusst nicht
            // in `$fillable`, weil sie nie aus einer Eingabe stammen darf.
            $account->password_login_disabled_at = now();
            $account->saveOrFail();

            return $account;
        });

        if ($account === null) {
            return false;
        }

        // Nichts geschrieben, nichts aufzuräumen: Die Wege aus dem Passwort hat
        // schon das erste Abschalten geschlossen.
        if (!$account->wasChanged('password_login_disabled_at')) {
            return true;
        }

        // Committet ist, der Passwort-Login ist abgeschaltet — was folgt, räumt nur
        // noch hinterher auf. Der Eintrag steht vor diesen Schreibungen, sonst
        // fehlte der Nachweis ausgerechnet dann, wenn dort etwas schiefging.
        $this->recordAuthActivity($account, ActivityEvent::PASSWORD_LOGIN_DISABLED);

        // Abgeschaltet wird der Passwort-Login, wenn ein Verdacht besteht. Eine
        // fremde Sitzung und ein mit dem Passwort erlangter Recaller-Cookie liefen
        // sonst weiter — die Abschaltung käme für genau den Fall zu spät, für den
        // sie gedacht ist. Der Recaller-Pfad fragt die Spalte nie, und der
        // Passkey-Login stellt keinen Cookie aus.
        $revokedSessionCount = $this->sessionRevoker->revokeFor($account);

        // Derselbe Widerruf über das Sitzungs-Formular schreibt diesen Eintrag
        // ebenfalls; eine Abfrage findet damit beide Wege, auf denen fremde
        // Sitzungen enden. Und wie dort nur, wenn die Sitzungen in der Datenbank
        // liegen — sonst kam der Widerruf an keine heran, und der Eintrag
        // behauptete Sitzungsenden, die es nicht gab. Die Token-Rotation läuft
        // trotzdem; sie ist eine Folge der Abschaltung ohne eigene Aussage.
        if ($this->sessionTerminator->usesDatabaseDriver()) {
            $this->recordAuthActivity($account, ActivityEvent::OTHER_SESSIONS_LOGGED_OUT, [
                'terminated_session_count' => $revokedSessionCount,
            ]);
        }

        // Aus demselben Grund der offene Link zum Zurücksetzen: Er lebt bis zu
        // einer Stunde weiter, und der Reset weist ihn nur ab, solange der
        // Passwort-Login abgeschaltet ist — ist er wieder offen, setzt der Link
        // ein Passwort.
        Password::deleteToken($account);

        return true;
    }

    public function enablePasswordLogin(User $user): bool
    {
        $account = DB::transaction(function () use ($user): ?User {
            $account = $this->lockAccount($user->id);

            if (!$account->isPasswordLoginDisabled()) {
                return null;
            }

            $account->reopenPasswordLogin();
            $account->saveOrFail();

            return $account;
        });

        if ($account === null) {
            return false;
        }

        // Ohne vorheriges Abschalten beschriebe der Eintrag ein Ereignis, das es
        // nie gab — daher erst hier, hinter der Entscheidung der gesperrten Zeile.
        $this->recordAuthActivity($account, ActivityEvent::PASSWORD_LOGIN_ENABLED);

        return true;
    }

    public function deletePasskey(PasskeyCredential $passkey): bool
    {
        $account = DB::transaction(function () use ($passkey): ?User {
            $account = $this->lockAccount($passkey->user_id);

            // Neu gelesen hinter dem Lock: Eine zweite Löschung, die den Passkey
            // noch vor dem Commit der ersten fand, träfe sonst keine Zeile mehr,
            // bekäme von Eloquent trotzdem Erfolg gemeldet und verschickte
            // dieselbe Entfernung ein zweites Mal.
            $passkey->refresh();

            // Gezählt wird hinter dem Lock: Zwei gleichzeitige Löschungen sähen
            // sonst beide den vorletzten Passkey und nähmen gemeinsam auch den
            // letzten mit. Ein Konto mit offenem Passwort-Login braucht die
            // Zählung nicht — ihm bleibt ohnehin ein Anmeldeweg.
            if ($account->isPasswordLoginDisabled() && $this->passkeys->countForUser($account) <= 1) {
                return null;
            }

            $this->passkeys->delete($passkey);

            return $account;
        });

        if ($account === null) {
            return false;
        }

        // Der Passkey ist schon gelöscht: Ein durchgereichter Fehler beim
        // Einreihen der Mail machte aus der vollzogenen Löschung einen
        // Fehlschlag.
        try {
            // Sprach-Snapshot: Die Mail rendert erst im Worker, der keine Session
            // kennt und sonst auf `APP_LOCALE` zurückfiele.
            $account->notify(
                (new PasskeyChangedNotification(PasskeyChange::REMOVED, $passkey->name))->locale(app()->getLocale()),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return true;
    }

    private function lockAccount(int $userId): User
    {
        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Im `auth`-Kanal statt über die `logOnly`-Allowlist des Models, damit der
     * Vorgang neben den 2FA-Ein-/Ausschaltungen steht — beide beantworten dieselbe
     * Frage: Wer hat wann einen Anmeldeweg verändert?
     *
     * Ein durchgereichter Insert-Fehler machte aus dem vollzogenen Wechsel einen
     * Fehlschlag und hielte auf, was hinter dem Eintrag noch ansteht. Worüber der
     * Vorgang ausgeht, darf nicht am Audit-Sink hängen.
     *
     * @param array<string, mixed> $properties
     */
    private function recordAuthActivity(User $user, ActivityEvent $event, array $properties = []): void
    {
        try {
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event($event->value)
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties($properties)
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
