<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Models\Role;

/**
 * Schreibt Activity-Log-Einträge bei Anlage und Löschung von Rollen.
 *
 * `Spatie\Permission\Models\Role` ist ein Vendor-Model und nutzt den
 * `LogsActivity`-Trait nicht. Ein Subclassing samt `config/permission.php`-
 * Override wäre für rein audit-bezogene Zwecke unverhältnismäßig — ein
 * Observer hängt sich extern an die Eloquent-Lifecycle-Events des
 * Vendor-Models und deckt damit alle Aufruf-Quellen (CLI, Seeder,
 * künftiges Admin-UI, Tinker-Eingriffe) in einer Klasse ab.
 *
 * Symmetrie: Schreibt auf den gleichen Log-Channel (`role`) wie
 * `LogRoleChangeListener` (Pivot-Attach/Detach). Rollen-Lifecycle und
 * Rollen-Zuweisung gehören fachlich zusammen — dieselbe Filter-Sicht
 * im Activity-Log-UI zeigt beide Vorgänge.
 *
 * CLI-Actor: Wird automatisch über den zentralen `Activity::saving`-Hook
 * im `AppServiceProvider` ergänzt (Quelle: `CaptureConsoleActorListener` /
 * `ConsoleActorContext`). Dieser Observer muss dafür nichts tun.
 *
 * Single-Guard-Annahme: `properties.guard_name` wird mitgeschrieben, weil
 * derselbe Rollen-Name in einem Multi-Guard-Setup mehrfach existieren
 * kann (vgl. analoger Hinweis im `LogRoleChangeListener`). Das Projekt
 * läuft heute nur mit dem `web`-Guard, der Eintrag ist aber bereits
 * forensisch eindeutig, sobald sich das ändert.
 */
final class RoleLifecycleObserver
{
    public function created(Role $role): void
    {
        Activity::useLog(ActivityChannel::ROLE->value)
            ->performedOn($role)
            ->withProperties([
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ])
            ->event(ActivityEvent::ROLE_CREATED->value)
            ->log(ActivityEvent::ROLE_CREATED->description());
    }

    public function deleted(Role $role): void
    {
        // Properties bewusst aus dem Model lesen, solange es noch befüllt
        // ist: nach dem DELETE zeigt `subject_id` ins Leere, nur die
        // Properties bleiben dauerhaft forensisch verwertbar.
        Activity::useLog(ActivityChannel::ROLE->value)
            ->performedOn($role)
            ->withProperties([
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ])
            ->event(ActivityEvent::ROLE_DELETED->value)
            ->log(ActivityEvent::ROLE_DELETED->description());
    }

    /**
     * Detacht zugewiesene User explizit, bevor die DB-Cascade auf
     * `model_has_roles` greift. Hintergrund: Der FK auf der Pivot-Tabelle
     * ist mit `cascadeOnDelete()` definiert, die DB löscht die Pivot-
     * Zeilen also direkt — Spatie sieht das nicht, kein
     * `RoleDetachedEvent` würde feuern, und der `LogRoleChangeListener`
     * bliebe stumm. Folge: der User-Verlust wäre im Activity-Log
     * unsichtbar. Über `$user->removeRole($role)` feuert Spatie das
     * Event sauber, der bestehende Listener schreibt pro User einen
     * `role_detached`-Eintrag, die nachgelagerte DB-Cascade greift
     * dann nur noch ins Leere.
     *
     * **Achtung Methoden-Name:** bewusst NICHT `deleting` — Spatie's
     * `HasPermissions::bootHasPermissions()` registriert während des
     * Role-Model-Boots einen eigenen `static::deleting`-Hook, der via
     * raw `$role->users()->detach()` aufräumt (ohne Events). Würde
     * unser Observer-`deleting` heißen, käme er erst NACH Spatie's
     * Trait-Listener dran (Trait bootet vor `Role::observe`), und die
     * Pivots wären schon weg. Diese Methode wird daher in
     * `AppServiceProvider::register()` via direktem
     * `Event::listen('eloquent.deleting: ' . Role::class, ...)`
     * registriert, **bevor** das Role-Model bootet — damit landen wir
     * im Dispatcher vor Spatie's Trait-Hook.
     */
    public static function detachUsersBeforeCascadeDelete(Role $role): void
    {
        foreach ($role->users()->get() as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $user->removeRole($role);
        }
    }
}
