<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Observers\RoleLifecycleObserver;
use App\Policies\PasskeyCredentialPolicy;
use App\Services\Auth\SelfRegistrationContext;
use App\Services\Console\ConsoleActorContext;
use App\Services\Console\Contracts\ConsoleActorContextContract;
use App\Services\Upload\Contracts\ProfilePhotoOptimizerContract;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use App\Services\Upload\ProfilePhotoOptimizer;
use App\Services\Upload\UploadLimitResolver;
use App\Services\User\Contracts\SelfEmailVerifierContract;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Contracts\UserEmailVerifierContract;
use App\Services\User\Contracts\UserHardDeleterContract;
use App\Services\User\Contracts\UserPasswordResetterContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use App\Services\User\SelfEmailVerifier;
use App\Services\User\UserEmailChanger;
use App\Services\User\UserEmailVerifier;
use App\Services\User\UserHardDeleter;
use App\Services\User\UserPasswordResetter;
use App\Services\User\UserSoftDeleter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserHardDeleterContract::class, UserHardDeleter::class);
        $this->app->bind(UserSoftDeleterContract::class, UserSoftDeleter::class);
        $this->app->bind(UserEmailVerifierContract::class, UserEmailVerifier::class);
        $this->app->bind(SelfEmailVerifierContract::class, SelfEmailVerifier::class);
        $this->app->bind(UserEmailChangerContract::class, UserEmailChanger::class);
        $this->app->bind(UserPasswordResetterContract::class, UserPasswordResetter::class);
        $this->app->bind(UploadLimitResolverContract::class, UploadLimitResolver::class);
        $this->app->bind(ProfilePhotoOptimizerContract::class, ProfilePhotoOptimizer::class);
        $this->app->singleton(ConsoleActorContextContract::class, ConsoleActorContext::class);
    }

    /**
     * Bootstrap any application services.
     */
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
        // Der Architektur-Test `tests/Feature/ActivityLog/MorphMapTest.php` schützt
        // zusätzlich gegen vergessene Einträge.
        Relation::enforceMorphMap([
            'user' => User::class,
            'passkey' => PasskeyCredential::class,
            // `Spatie\Permission\Models\Role` ist ein Vendor-Model, taucht
            // aber als `subject_type` im Activity-Log auf (siehe
            // `RoleLifecycleObserver`). Ohne Alias würde `enforceMorphMap`
            // jeden Schreibversuch mit `ClassMorphViolationException` brechen.
            // Vendor-Models in der Map sind selten, hier aber zwingend.
            'role' => Role::class,
        ]);

        Gate::policy(PasskeyCredential::class, PasskeyCredentialPolicy::class);

        // Vor dem Persistieren eines Passkey-Activity-Log-Eintrags den
        // generischen Eloquent-Event-Namen (created/updated/deleted) auf
        // einen fachlichen Code umlabeln (passkey_registered/_renamed/
        // _removed). Spatie's `LogsActivity`-Trait dieser Version bietet
        // keinen `tapActivity`-Hook; ein `saving`-Listener auf dem Activity-
        // Model ist der einzige stabile Punkt zwischen Trait-Setup und Insert.
        // Die Mapping-Logik bleibt im Domain-Model (PasskeyCredential), hier
        // hängt nur der Listener dran — `boot()` läuft genau einmal pro
        // Application-Lifetime, daher keine Doppel-Registrierung.
        Activity::saving(static fn (Activity $activity) => PasskeyCredential::applyEventLabelToActivity($activity));

        // User-Self-Registration: der Web-Pfad (`RegisteredUserController` →
        // `CreateNewUser::create()`) erzeugt durch das `LogsActivity`-Trait
        // einen generischen `user.created`-Eintrag. Ohne Remap wäre er nicht
        // vom Admin-CLI-Pfad (`user:create`) zu unterscheiden — fachlich aber
        // zwei grundverschiedene Vorgänge (Public-Endpoint vs. interner
        // Admin-Akt). `CreateNewUser` setzt vor `User::create()` einen
        // request-scoped Marker, der hier ausgewertet wird; ist er aktiv,
        // wird `event` auf `user_self_registered` umgelabelt und die
        // Description auf den fachlichen Übersetzungs-Key gesetzt. Ohne
        // Marker bleibt der Eintrag generisch (CLI/Tests/Seeder).
        //
        // Warum nicht im `User`-Model: `ModelsDependOnlyOnModelsTest`
        // verbietet Models den Zugriff auf `App\Services`. Die Remap-Logik
        // braucht aber den Marker-Zustand aus dem Service-Layer, daher
        // gehört sie hier ins Bootstrapping.
        Activity::saving(static function (Activity $activity): void {
            if ($activity->log_name !== 'user') {
                return;
            }

            if ($activity->event !== 'created') {
                return;
            }

            if (!SelfRegistrationContext::isActiveStatically()) {
                return;
            }

            $activity->event = 'user_self_registered';
            $activity->description = __('app.activity_user_self_registered');
        });

        // CLI-Actor & Event-Remap für Artisan-Commands. Hängt jedem während
        // einer Command-Ausführung entstehenden Eintrag das `cli_actor`-
        // Property an (OS-User/Hostname/Command) und labelt für eine
        // kuratierte Liste von User-Lifecycle-Commands den generischen
        // Eloquent-Event-Code auf einen fachlichen Code um — symmetrisch
        // zum Self-Registration-Hook darüber.
        Activity::saving(static fn (Activity $activity) => ConsoleActorContext::applyToActivity($activity));

        // Rollen-Lifecycle (Anlage/Löschung) ins Activity-Log spiegeln.
        // `Spatie\Permission\Models\Role` ist ein Vendor-Model ohne
        // `LogsActivity`-Trait; ein Observer hängt sich extern an die
        // Eloquent-Lifecycle-Events und deckt damit alle Aufruf-Quellen
        // (CLI, Seeder, künftiges Admin-UI) ab. Ein hier konfiguriertes
        // Custom-Role-Wrapper-Model wäre der einzige Grund, statt der
        // konkreten Vendor-Klasse `config('permission.models.role')`
        // aufzulösen — das Projekt nutzt das Default-Model, daher direkt.
        Role::observe(RoleLifecycleObserver::class);

        // Passkey authentication options (guests): IP-based, 20 requests per minute.
        // Limits e-mail enumeration via the allowCredentials field without impacting
        // legitimate UX (a real user needs at most one options call per login attempt).
        RateLimiter::for(
            'passkey-authenticate-options',
            static fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()),
        );

        // Passkey authentication (guests): IP-based, 5 attempts per minute.
        // Protects against credential enumeration and brute-force via the public endpoint.
        RateLimiter::for(
            'passkey-authenticate',
            static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()),
        );

        // Passkey registration (authenticated users): user-based, 5 attempts per minute.
        // Falls back to IP when no authenticated user is available.
        RateLimiter::for('passkey-register', static function (Request $request): Limit {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute(5)->by((string) $user->id)
                : Limit::perMinute(5)->by($request->ip());
        });

        // Deferred-Email-Change confirm/cancel endpoints (guests, IP-basiert):
        // schützt gegen Token-Probieren auf den 64-Hex-Tokens. 10/min ist
        // großzügig genug für legitime Mehrfach-Klicks (z. B. Antiviren-Prefetch
        // + User-Klick) und eng genug, um Brute-Force-Versuche praktisch wertlos
        // zu machen — der Suchraum ist ohnehin 2^256.
        RateLimiter::for(
            'email-change-link',
            static fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()),
        );
    }
}
