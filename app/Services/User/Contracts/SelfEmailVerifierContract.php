<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Self-service E-Mail-Verifizierung über den signierten Link aus
 * `Illuminate\Auth\Notifications\VerifyEmail`. Aufrufer ist der
 * `VerifyEmailController`; der Hash-Vergleich ist Teil des Service-Vertrags,
 * weil das Activity-Logging der Fehlversuche untrennbar damit verknüpft ist
 * (Controller dürfen `Spatie\Activitylog` nicht direkt verwenden — siehe
 * `ControllersAreIndependentTest`).
 *
 * Abgrenzung zum {@see UserEmailVerifierContract}: Beide Pfade schreiben
 * denselben Event-Code (`email_verified`); die Unterscheidung steckt im
 * Causer. Self-Verify (dieser Service) dispatcht den `Verified`-Event,
 * der `LogAuthenticationActivityListener` setzt den User als Causer.
 * Admin-/CLI-Verify (`UserEmailVerifierContract`) schreibt direkt mit
 * `causedByAnonymous()` plus `cli_actor`-Property. Reports trennen die
 * Fälle damit über `causer_id IS NULL` bzw. den Causer-Vergleich.
 *
 * Wirft `App\Services\User\Exceptions\SelfEmailVerificationFailedException`,
 * wenn die User-ID nicht auflöst (`reason='user_not_found'`) oder der Hash
 * nicht passt (`reason='hash_mismatch'`). In beiden Fällen wurde bereits
 * ein anonymisierter `email_verification_failed`-Audit-Eintrag geschrieben.
 */
interface SelfEmailVerifierContract
{
    /**
     * Idempotenz-Hinweis: Der Aufrufer prüft `hasVerifiedEmail()` und ruft
     * `verify()` nur, wenn der User noch nicht verifiziert ist — dieser
     * Service verifiziert bedingungslos und dispatcht den `Verified`-Event.
     */
    public function verify(int $userId, string $hash): User;
}
