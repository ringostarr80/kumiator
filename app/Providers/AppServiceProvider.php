<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Observers\RoleLifecycleObserver;
use App\Policies\PasskeyCredentialPolicy;
use App\Services\Audit\AuthorizationAuditor;
use App\Services\Audit\Contracts\AuthorizationAuditorContract;
use App\Services\Audit\Contracts\SanctumTokenAuditorContract;
use App\Services\Audit\SanctumTokenAuditor;
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Console\ConsoleActorContext;
use App\Services\Console\Contracts\ConsoleActorContextContract;
use App\Services\Schedule\HealthcheckPingPhase;
use App\Services\Schedule\ScheduleHealthcheckPinger;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\Session\UserSessionTerminator;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use App\Services\Upload\ProfilePhotoOptimizer;
use App\Services\Upload\UploadLimitResolver;
use App\Services\User\Contracts\EmailVerificationResenderContract;
use App\Services\User\Contracts\SelfEmailVerifierContract;
use App\Services\User\Contracts\UserApproverContract;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Contracts\UserEmailVerifierContract;
use App\Services\User\Contracts\UserHardDeleterContract;
use App\Services\User\Contracts\UserPasswordResetterContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use App\Services\User\EmailVerificationResender;
use App\Services\User\SelfEmailVerifier;
use App\Services\User\UserApprover;
use App\Services\User\UserEmailChanger;
use App\Services\User\UserEmailVerifier;
use App\Services\User\UserHardDeleter;
use App\Services\User\UserPasswordResetter;
use App\Services\User\UserSoftDeleter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserApproverContract::class, UserApprover::class);
        $this->app->bind(UserHardDeleterContract::class, UserHardDeleter::class);
        $this->app->bind(UserSoftDeleterContract::class, UserSoftDeleter::class);
        $this->app->bind(UserEmailVerifierContract::class, UserEmailVerifier::class);
        $this->app->bind(SelfEmailVerifierContract::class, SelfEmailVerifier::class);
        $this->app->bind(EmailVerificationResenderContract::class, EmailVerificationResender::class);
        $this->app->bind(UserEmailChangerContract::class, UserEmailChanger::class);
        $this->app->bind(UserPasswordResetterContract::class, UserPasswordResetter::class);
        $this->app->bind(UploadLimitResolverContract::class, UploadLimitResolver::class);
        $this->app->bind(ProfilePhotoOptimizerContract::class, ProfilePhotoOptimizer::class);
        $this->app->bind(UserSessionTerminatorContract::class, UserSessionTerminator::class);
        $this->app->bind(SanctumTokenAuditorContract::class, SanctumTokenAuditor::class);
        $this->app->bind(AuthorizationAuditorContract::class, AuthorizationAuditor::class);
        $this->app->singleton(ConsoleActorContextContract::class, ConsoleActorContext::class);

        // Beim Löschen einer Rolle räumt Spatie die User-Zuordnungen still ab
        // (ohne Event) — der Entzug würde sonst nicht im Activity-Log landen.
        // Unser Listener wird darum schon in `register()` eingehängt, damit er
        // vor Spatie's eigenem Hook (gesetzt beim Model-Boot) läuft und loggt.
        Event::listen(
            'eloquent.deleting: ' . Role::class,
            [RoleLifecycleObserver::class, 'detachUsersBeforeCascadeDelete'],
        );
    }

    public function boot(): void
    {
        // Stabile Aliase für polymorphe Beziehungen — primär für den Activity-Log,
        // damit `subject_type` / `causer_type` keine FQCN enthalten (refactor-sicher,
        // entkoppelt DB-Daten von Klassen-Namen, übersetzbar im UI).
        //
        // `enforceMorphMap` (statt `morphMap`) wirft eine `ClassMorphViolationException`,
        // sobald ein Model mit polymorpher Beziehung NICHT in der Map steht. Das ist
        // beabsichtigt: bei einem neuen Loggable-Model fliegt der Fehler sofort beim
        // ersten Schreibversuch, statt jahrelang stille FQCN in die DB zu schreiben.
        Relation::enforceMorphMap([
            'user' => User::class,
            'passkey' => PasskeyCredential::class,
            // Vendor-Model, wird aber als `subject_type` geloggt (Rollen-Lifecycle)
            // und braucht darum ebenfalls einen Alias.
            'role' => Role::class,
        ]);

        Gate::policy(PasskeyCredential::class, PasskeyCredentialPolicy::class);

        // Zentrale Audit-Vorsorge für abgelehnte Autorisierungen: ein einziger
        // Hook schreibt den `authorization_denied`-Eintrag, statt das Muster pro
        // Aufrufstelle zu kopieren. Auditiert wird nur, wenn das Subjekt sich per
        // `AuthorizationAuditable` anmeldet (Details + Warum opt-in im Auditor).
        // Rückgabe muss void/null bleiben, sonst kippt der Hook über `$result ??=`
        // die Entscheidung einer undefinierten Ability.
        //
        // Der Auditor ist zustandslos und wird darum einmal aufgelöst und ins
        // Closure geschlossen, statt ihn bei jeder Autorisierungsprüfung neu aus
        // dem Container zu bauen.
        $authorizationAuditor = $this->app->make(AuthorizationAuditor::class);

        /** @param array<array-key, mixed> $arguments */
        $auditDeniedAuthorization = static function (
            ?Authenticatable $user,
            string $ability,
            mixed $result,
            array $arguments,
        ) use ($authorizationAuditor): void {
            $authorizationAuditor->record($user, $ability, $result, $arguments);
        };

        Gate::after($auditDeniedAuthorization);

        // Alle Vor-Insert-Anpassungen am Audit-Eintrag in einer `Activity::saving`-
        // Closure: Ein Listener statt vier spart pro Insert drei Dispatcher-Durchläufe
        // und erzwingt die unten nötige Reihenfolge strukturell. Die Closure trägt nur,
        // solange jeder Eintrag über ein Model-Save entsteht — warum Spaties Buffer
        // darum nicht angeboten wird, steht in `config/activitylog.php`.
        Activity::saving(static function (Activity $activity): void {
            // Generischen Eloquent-Event-Namen (created/updated/deleted/restored) auf
            // einen fachlichen Code umlabeln; die Mapping-Logik selbst bleibt im
            // jeweiligen Domain-Model, hier wird sie nur angestoßen. Das User-Mapping ist
            // channel-agnostisch — derselbe Code, egal ob CLI, Web oder Seeder den Vorgang
            // auslöst; der Auslöse-Kanal wird separat über Properties und nachgelagerte
            // Hooks markiert.
            PasskeyCredential::applyEventLabelToActivity($activity);
            User::applyEventLabelToActivity($activity);

            // User-Self-Registration vs. Admin/CLI-Anlage unterscheidbar machen: beide
            // erzeugen denselben `user_created`-Eintrag (oben aus dem rohen `created`
            // aufgewertet), sind fachlich aber grundverschieden — Public-Endpoint vs.
            // interner Admin-Akt. Nur der Web-Self-Reg-Pfad setzt vor `User::create()`
            // einen request-scoped Marker (`SelfRegistrationContext`); fehlt er (CLI/
            // Tests/Seeder), bleibt der Eintrag `user_created`. Steht nach dem User-Mapper,
            // weil der Match auf dem bereits aufgewerteten `user_created` beruht, nicht auf
            // rohem `created`. Liegt hier statt im `User`-Model, weil die Architektur-Regel
            // Models den Zugriff auf `App\Services` (Marker-Zustand) verbietet.
            if (
                $activity->log_name === ActivityChannel::USER->value
                && $activity->event === ActivityEvent::USER_CREATED->value
                && app(SelfRegistrationContextContract::class)->isActive()
            ) {
                $activity->event = ActivityEvent::USER_SELF_REGISTERED->value;
            }

            // CLI-Effekte (cli_actor-Property + Causer-Anonymisierung) an jeden Eintrag
            // hängen, der während eines Artisan-Commands entsteht. Details im Klassen-
            // PHPDoc von ConsoleActorContext.
            ConsoleActorContext::applyToActivity($activity);
        });

        // Rollen-Lifecycle (Anlage/Löschung) ins Activity-Log spiegeln; warum
        // per Observer statt Trait, steht im Klassen-PHPDoc von
        // RoleLifecycleObserver.
        Role::observe(RoleLifecycleObserver::class);

        // Fluent-Macro, damit Schedule-Definitionen (routes/console.php) einen Job
        // per `->withHealthcheck($slug)` an Healthchecks.io-Monitoring hängen.
        // Ping-Mechanik (Auto-Provisioning, geschluckte Fehler, No-Op ohne Ping-Key)
        // steckt im ScheduleHealthcheckPinger.
        ScheduledEvent::macro('withHealthcheck', function (string $slug): ScheduledEvent {
            $pinger = app(ScheduleHealthcheckPinger::class);

            return $this
                ->before(static fn () => $pinger->ping($slug, HealthcheckPingPhase::Start))
                ->onSuccess(static fn () => $pinger->ping($slug, HealthcheckPingPhase::Success))
                ->onFailure(static fn () => $pinger->ping($slug, HealthcheckPingPhase::Failure));
        });

        // Bremst E-Mail-Enumeration über das `allowCredentials`-Feld, ohne legitime
        // UX zu treffen — ein echter Login braucht höchstens einen Options-Call.
        RateLimiter::for(
            'passkey-authenticate-options',
            static fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()),
        );

        // Begrenzt Credential-Enumeration und Brute-Force über den öffentlichen
        // Authentifizierungs-Endpoint.
        RateLimiter::for(
            'passkey-authenticate',
            static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()),
        );

        RateLimiter::for('passkey-register', static function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute(5)->by((string) $user->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // Öffentliche Confirm/Cancel-Endpoints (Gäste): deckelt Endpoint-Missbrauch.
        // Token-Raten ist schon durch die 256-Bit-Entropie aussichtslos — das Limit
        // schützt also gegen Last/Hämmern, nicht gegen Token-Brute-Force. 10/min ist
        // großzügig für legitime Mehrfach-Klicks (z. B. Antiviren-Prefetch + User-Klick).
        RateLimiter::for(
            'email-change-link',
            static fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()),
        );
    }
}
