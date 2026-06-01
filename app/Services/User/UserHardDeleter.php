<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\User\Contracts\UserHardDeleterContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

/**
 * DSGVO-konformer Hard-Delete für einen Benutzer.
 *
 * Aufrufer:
 *  - `App\Actions\Jetstream\DeleteUser` (Self-Delete; Event `account_self_deleted`)
 *  - `App\Console\Commands\User\ForceDelete` (Admin-Pfad; Event `account_admin_force_deleted`)
 *
 * Nach Abschluss darf kein personenbezogenes Restmaterial des Users in der
 * `activity_log`-Tabelle stehen — ein einziger anonymer Audit-Eintrag bleibt
 * zurück (Brücke zwischen DSGVO Art. 17 und Art. 32). Der admin-initiierte
 * Soft-Delete (`user:delete`) verzichtet bewusst auf diesen Purge.
 *
 * `DB::table()`-Ausnahmen (Default ist Eloquent):
 *  - Sessions: kein Default-Eloquent-Model, Tabellenname konfigurierbar.
 *  - `model_has_roles`: Pivot ohne öffentliches Spatie-Model; `roles()->detach()`
 *    würde `RoleDetachedEvent` feuern und damit den Purge wieder unterlaufen.
 *
 * Session-Treiber: Die Session-Löschung wirkt nur bei `session.driver = database`.
 * Bei Redis/File/Cookie bleiben Payloads bis zum TTL-Ablauf liegen; für den
 * Auth-Schutz reicht das, weil `EloquentUserProvider::retrieveById()` den hart
 * gelöschten User nicht mehr findet. Bei Treiber-Wechsel neu bewerten.
 */
final class UserHardDeleter implements UserHardDeleterContract
{
    public function forceDelete(User $user, ActivityEvent $event): void
    {
        DB::transaction(static function () use ($user, $event): void {
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

            // DB::table-Ausnahme #1 (Sessions): kein Default-Eloquent-Model,
            // Tabellenname konfigurierbar.
            if (Config::string('session.driver') === 'database') {
                DB::table(Config::string('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            // DB::table-Ausnahme #2 (Spatie-Pivot): `roles()->detach()` würde
            // `RoleDetachedEvent` → `LogRoleChangeListener` triggern und genau
            // den Activity-Eintrag erzeugen, den wir hier vermeiden wollen.
            // `Model::withoutEvents()` greift nur Eloquent-Events, nicht die
            // Spatie-eigenen — direkter DELETE ist der einzige saubere Weg.
            DB::table('model_has_roles')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->delete();

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

        $user->deleteProfilePhoto();
    }
}
