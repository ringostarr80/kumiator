<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserHardDeleterContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

/**
 * DSGVO-konformer Hard-Delete für einen Benutzer.
 *
 * Das `$event`-Argument trägt die fachliche Löschvariante: Self-Delete
 * (`account_self_deleted`) oder administrativer Force-Delete
 * (`account_admin_force_deleted`).
 *
 * Nach Abschluss darf kein personenbezogenes Restmaterial des Users in der
 * `activity_log`-Tabelle stehen — ein einziger anonymer Audit-Eintrag bleibt
 * zurück (Brücke zwischen DSGVO Art. 17 und Art. 32). Der admin-initiierte
 * Soft-Delete (`user:delete`) verzichtet bewusst auf diesen Purge.
 *
 * Rollen- und Direkt-Permission-Pivots brauchen keinen eigenen Purge:
 * Spaties `deleting`-Hooks detachen beide beim Force-Delete still — ohne
 * Spatie-Event, es entsteht also kein Activity-Eintrag, der den Purge
 * unterliefe.
 *
 * Session-Treiber: Die Session-Löschung wirkt nur bei `session.driver = database`.
 * Bei Redis/File/Cookie bleiben Payloads bis zum TTL-Ablauf liegen; für den
 * Auth-Schutz reicht das, weil `EloquentUserProvider::retrieveById()` den hart
 * gelöschten User nicht mehr findet. Bei Treiber-Wechsel neu bewerten.
 */
final class UserHardDeleter implements UserHardDeleterContract
{
    public function __construct(private readonly UserSessionTerminatorContract $sessionTerminator)
    {
    }

    public function forceDelete(User $user, ActivityEvent $event): void
    {
        DB::transaction(function () use ($user, $event): void {
            // Mass-`delete()` statt `each->deleteOrFail()`: vermeidet, dass der
            // `LogsActivity`-Trait pro Token/Passkey Activity-Einträge mit
            // Causer/Subject-Verweis auf den gleich danach hart gelöschten User
            // schreibt — würde dem „Recht auf Vergessen" widersprechen. Im
            // admin-initiierten Soft-Delete (`user:delete`) ist das umgekehrt
            // erwünscht und läuft dort über die Eloquent-Events.
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->delete();

            PasskeyCredential::query()->where('user_id', $user->getKey())->delete();

            // Sonst schriebe `forceDelete()` unten einen `deleted`-Eintrag
            // mit `subject_id = $user->getKey()`.
            $user->disableLogging();

            $user->forceDelete();

            // Bestehende Subject-/Causer-Referenzen auf diesen User abräumen
            // (Alt-Einträge mit ID, ggf. Namen in `properties`). Fremde
            // Historie bleibt unangetastet.
            ActivityModel::query()
                ->where(static function (Builder $query) use ($user): void {
                    $query->where('subject_type', $user->getMorphClass())
                        ->where('subject_id', $user->getKey());
                })
                ->orWhere(static function (Builder $query) use ($user): void {
                    $query->where('causer_type', $user->getMorphClass())
                        ->where('causer_id', $user->getKey());
                })
                ->delete();

            // Anonymer Audit-Eintrag NACH dem Purge, damit ihn der Purge nicht
            // erfasst. `causedByAnonymous()` ist kritisch: Spatie würde sonst
            // über den `CauserResolver` den noch im `Auth::user()`-Cache
            // liegenden — gerade hart gelöschten — User als Causer eintragen.
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event($event->value)
                ->causedByAnonymous()
                ->log($event->description());
        });

        // Wie `deleteProfilePhoto()` ein nicht-rollbackbarer Seiteneffekt nach
        // dem Commit: `session.connection` kann auf einer anderen Connection
        // liegen als die Transaktion und gehörte dann nicht zu deren Rollback.
        $this->sessionTerminator->deleteForUser($user);

        $user->deleteProfilePhoto();
    }
}
