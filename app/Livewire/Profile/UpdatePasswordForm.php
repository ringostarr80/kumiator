<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Jetstream\Http\Livewire\UpdatePasswordForm as JetstreamUpdatePasswordForm;
use Spatie\Activitylog\Facades\Activity;

/**
 * Erweiterung der Jetstream-Komponente um Activity-Log-Erfassung.
 *
 * Jetstream feuert im Form-Pfad kein `PasswordUpdatedViaController`, sodass der
 * `LogAuthenticationActivityListener` hier keinen Eintrag bekäme. Über den
 * HTTP-Endpoint löst Fortifys Controller das Event aus und der Listener
 * schreibt — für den Livewire-Pfad schreiben wir den `password_updated`-Audit
 * hier direkt, nachdem der Parent den Wechsel erfolgreich abgeschlossen hat.
 */
final class UpdatePasswordForm extends JetstreamUpdatePasswordForm
{
    public function updatePassword(UpdatesUserPasswords $updater): void
    {
        parent::updatePassword($updater);

        // Der Parent wirft `ValidationException` bei falschem/ungültigem
        // Passwort; hier angekommen war der Wechsel erfolgreich und der Nutzer
        // ist am `web`-Guard authentifiziert.
        /** @var \App\Models\User $user */
        $user = Auth::user();

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_UPDATED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::PASSWORD_UPDATED->description());
    }
}
