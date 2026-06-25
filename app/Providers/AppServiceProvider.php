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
use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Console\ConsoleActorContext;
use App\Services\Console\Contracts\ConsoleActorContextContract;
use App\Services\Permission\PermissionSeederContext;
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
        $this->app->singleton(ConsoleActorContextContract::class, ConsoleActorContext::class);

        // `scoped`, damit Setzer (Seeder) und Leser (`LogPermissionChangeListener`)
        // dieselbe Request-Instanz sehen.
        $this->app->scoped(PermissionSeederContext::class);

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
        /** @param array<array-key, mixed> $arguments */
        $auditDeniedAuthorization = static function (
            ?Authenticatable $user,
            string $ability,
            mixed $result,
            array $arguments,
        ): void {
            app(AuthorizationAuditor::class)->record($user, $ability, $result, $arguments);
        };

        Gate::after($auditDeniedAuthorization);

        // Vor dem Persistieren eines Passkey-Activity-Log-Eintrags den generischen
        // Eloquent-Event-Namen (created/updated/deleted) auf einen fachlichen Code
        // umlabeln. Spatie's `LogsActivity`-Trait schreibt den Eintrag automatisch
        // über die Eloquent-Lifecycle-Events, ohne Callback zum Anpassen des
        // `event`-Felds; ein `saving`-Listener auf dem Activity-Model ist daher der
        // einzige stabile Punkt zwischen Trait-Setup und Insert. Die Mapping-Logik
        // selbst bleibt im Domain-Model (PasskeyCredential), hier hängt nur der
        // Listener dran.
        Activity::saving(static fn (Activity $activity) => PasskeyCredential::applyEventLabelToActivity($activity));

        // Analog zum Passkey-Hook, für das User-Model: generische Eloquent-Lifecycle-
        // Events (created/updated/deleted/restored) auf fachliche Codes umlabeln.
        // Channel-agnostisch — derselbe Code, egal ob CLI, Web oder Seeder den Vorgang
        // ausgelöst hat; der Auslöse-Kanal wird separat über Properties und
        // nachgelagerte Hooks markiert.
        Activity::saving(static fn (Activity $activity) => User::applyEventLabelToActivity($activity));

        // User-Self-Registration vs. Admin/CLI-Anlage unterscheidbar machen: beide
        // erzeugen denselben `user_created`-Eintrag (vom Hook darüber aus dem rohen
        // `created` aufgewertet), sind fachlich aber grundverschieden — Public-Endpoint
        // vs. interner Admin-Akt. Nur der Web-Self-Reg-Pfad setzt vor `User::create()`
        // einen request-scoped Marker (`SelfRegistrationContext`); fehlt er
        // (CLI/Tests/Seeder), bleibt der Eintrag `user_created`.
        //
        // Reihenfolge ist wichtig: dieser Hook muss NACH dem User-Mapper laufen, weil
        // er auf den bereits aufgewerteten Code `user_created` matched, nicht auf den
        // rohen Eloquent-`created`.
        //
        // Warum hier statt im `User`-Model: unsere Architektur-Regel verbietet Models
        // den Zugriff auf `App\Services`, die Remap braucht aber den Marker-Zustand aus
        // dem Service-Layer — also gehört sie ins Bootstrapping.
        Activity::saving(static function (Activity $activity): void {
            if ($activity->log_name !== ActivityChannel::USER->value) {
                return;
            }

            if ($activity->event !== ActivityEvent::USER_CREATED->value) {
                return;
            }

            if (!app(SelfRegistrationContextContract::class)->isActive()) {
                return;
            }

            $activity->event = ActivityEvent::USER_SELF_REGISTERED->value;
        });

        // CLI-Effekte (cli_actor-Property + Causer-Anonymisierung) an jeden
        // Activity-Log-Eintrag hängen, der während eines Artisan-Commands
        // entsteht. Was genau passiert und warum, steht im Klassen-PHPDoc von
        // ConsoleActorContext.
        Activity::saving(static fn (Activity $activity) => ConsoleActorContext::applyToActivity($activity));

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
