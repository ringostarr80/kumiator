<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Notifications\AccountAlreadyExistsNotification;
use App\Notifications\CompleteRegistrationNotification;
use App\Services\Audit\AuditForensicProperties;
use App\Services\Auth\Contracts\ExistingAccountNotifierContract;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Facades\Activity;

final class ExistingAccountNotifier implements ExistingAccountNotifierContract
{
    private const NOTICE_DECAY_SECONDS = 3_600;

    public function notify(User $user): void
    {
        // Der Wiedereintritt läuft über die Wiederherstellung durch die
        // Administration; ein Hinweis „melde dich an" führte ins Leere.
        if ($user->trashed()) {
            return;
        }

        // Die Registrierung ist nur pro IP gedrosselt; von vielen IPs aus ließe
        // sich dasselbe Postfach sonst beliebig oft anschreiben. Überzählige
        // Versuche bleiben still, der Absender bekommt dieselbe Antwort wie bei
        // einer freien Adresse. Trifft das den Inhaber selbst, liegt die Mail
        // des ersten Versuchs schon in seinem Postfach.
        $key = 'existing-account-notice:' . $user->id;

        // Hochzählen und Prüfen in einem Schritt: Getrennt läsen gleichzeitige
        // Versuche alle denselben Stand und kämen alle durch.
        if (RateLimiter::hit($key, self::NOTICE_DECAY_SECONDS) > 1) {
            return;
        }

        // Der Inhaber bekommt eine Mail, die er nicht angefordert hat; fragt er
        // nach, muss der Versuch im Audit-Log seines Kontos stehen. Erst hinter
        // der Drossel, damit nur eine verschickte Mail einen Eintrag hinterlässt
        // und gedrosselte Versuche die Forensik-Tabelle nicht füllen. Kein
        // Causer: Der Absender ist nicht angemeldet.
        Activity::useLog(ActivityChannel::FORENSIC->value)
            ->event(ActivityEvent::REGISTRATION_EMAIL_TAKEN->value)
            ->performedOn($user)
            ->withProperties(AuditForensicProperties::fromRequest(request()))
            ->log('');

        $notification = $user->hasVerifiedEmail()
            ? new AccountAlreadyExistsNotification()
            : new CompleteRegistrationNotification();

        $user->notify($notification);
    }
}
