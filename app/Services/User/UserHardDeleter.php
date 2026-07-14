<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserHardDeleterContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

/**
 * DSGVO-konformer Hard-Delete für einen Benutzer.
 *
 * Das `$event`-Argument trägt die fachliche Löschvariante: Self-Delete
 * (`account_self_deleted`) oder administrativer Force-Delete
 * (`account_admin_force_deleted`).
 *
 * Nach Abschluss steht kein personenbezogenes Restmaterial des Users mehr in der
 * `activity_log`-Tabelle (Brücke zwischen DSGVO Art. 17 und Art. 32): Einträge mit
 * ihm als Subject werden gelöscht, seine Causer-Verweise anonymisiert; zurück bleibt
 * der anonyme Audit-Eintrag dieses Vorgangs. Passkey-Einträge erfasst keiner der
 * beiden Arme — ihr Subject ist die Credential, nicht der User —, sie tragen aber
 * von vornherein keinen Klartext-Namen und nach dem Löschen der Credential auch
 * keine auflösbare Referenz mehr. Der admin-initiierte Soft-Delete (`user:delete`)
 * verzichtet bewusst auf diesen Purge.
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
            // Mass-`delete()` statt `each->deleteOrFail()`: Sonst schriebe der
            // `LogsActivity`-Trait der Credential pro Passkey einen Entzugs-Eintrag,
            // dessen Subject die Credential ist — der Purge unten greift über den
            // User und ließe ihn als Verweis auf das gerade gelöschte Konto liegen
            // (DSGVO Art. 17). Tokens tragen den Trait nicht; für sie ist der
            // Mass-Delete schlicht der billigere Weg zum selben Ergebnis. Im
            // admin-initiierten Soft-Delete (`user:delete`) ist der Entzugs-Beleg
            // umgekehrt erwünscht und entsteht dort über die Model-Events bzw. den
            // Token-Auditor.
            $user->tokens()->delete();
            $user->passkeyCredentials()->delete();

            // Sonst schriebe `forceDelete()` unten einen `deleted`-Eintrag
            // mit `subject_id = $user->getKey()`.
            $user->disableLogging();

            $user->forceDelete();

            // Einträge, in denen der User Subject ist, tragen seine
            // personenbezogenen Daten (ID, ggf. Name in `properties`) → löschen
            // (DSGVO Art. 17).
            ActivityModel::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->delete();

            // Einträge, die der User als Causer ausgelöst hat, belegen
            // sicherheitsrelevante Zugriffe/Aktionen an fremden oder keinen
            // Subjects (etwa wer den Mitglieder-Audit-Trail eingesehen hat),
            // deren Nachvollziehbarkeit Art. 5(2)/32 verlangt. Den Beleg erhalten
            // und nur die Personen-Verknüpfung kappen — die einzige PII des Users
            // steckt hier in `causer_*`, nie in `properties`.
            ActivityModel::query()
                ->where('causer_type', $user->getMorphClass())
                ->where('causer_id', $user->getKey())
                ->update(['causer_type' => null, 'causer_id' => null]);

            // Anonymer Audit-Eintrag NACH dem Purge, damit ihn der Purge nicht
            // erfasst. `causedByAnonymous()` ist kritisch: Spatie würde sonst
            // über den `CauserResolver` den noch im `Auth::user()`-Cache
            // liegenden — gerade hart gelöschten — User als Causer eintragen.
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event($event->value)
                ->causedByAnonymous()
                ->log($event->description());
        });

        // Nicht-rollbackbarer Seiteneffekt bewusst NACH dem Commit:
        // `session.connection` kann auf einer anderen Connection liegen als die
        // Transaktion und gehörte dann nicht zu deren Rollback.
        $this->sessionTerminator->deleteForUser($user);

        // Die Foto-Datei direkt über den Storage abräumen statt über
        // `deleteProfilePhoto()`: dessen abschließendes `save()` liefe auf dem
        // per `forceDelete()` entfernten Model (`exists=false`) als INSERT und
        // legte den User samt E-Mail/Passwort-Hash wieder an.
        $photoPath = $user->profile_photo_path;

        if ($photoPath !== null) {
            Storage::disk($user->profilePhotoDiskName())->delete($photoPath);
        }
    }
}
