<?php

declare(strict_types=1);

namespace App\Observers;

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
        Activity::useLog('role')
            ->performedOn($role)
            ->withProperties([
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ])
            ->event('role_created')
            ->log(__('app.activity_role_created'));
    }

    public function deleted(Role $role): void
    {
        // Properties bewusst aus dem Model lesen, solange es noch befüllt
        // ist: nach dem DELETE zeigt `subject_id` ins Leere, nur die
        // Properties bleiben dauerhaft forensisch verwertbar.
        Activity::useLog('role')
            ->performedOn($role)
            ->withProperties([
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ])
            ->event('role_deleted')
            ->log(__('app.activity_role_deleted'));
    }
}
