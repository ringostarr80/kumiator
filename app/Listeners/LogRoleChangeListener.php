<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

/**
 * Schreibt Activity-Log-Einträge bei Rollen-Zuweisungen und -Entzügen.
 *
 * Spatie\Permission feuert keine Eloquent-Events auf dem User, weil Rollen
 * über eine Pivot-Tabelle verknüpft sind. Ohne diesen Listener bliebe der
 * fachlich wichtige Vorgang „Wer hat wann welche Rolle bekommen / verloren?"
 * ungeloggt.
 *
 * Registrierung: Läuft über Laravels Event-Auto-Discovery (standardmäßig via
 * `Application::configure()->withEvents()`, ausgewertet über die Event-Typ-Hints
 * der `handle*`-Methoden). Der Listener darf deshalb **nicht** zusätzlich via
 * `Event::listen()` registriert werden — sonst feuert er doppelt und jede
 * Rollenänderung landet zweimal im Activity-Log. Der Regressionstest
 * `RoleChangeActivityLogTest::testAssigningMultipleRolesAtOnceLogsSingleEntryWithAllRoleNames`
 * deckt genau das ab.
 *
 * Single-Guard-Annahme: Die in `properties.roles` geloggten Namen tragen keine
 * Guard-Information mit. Das ist heute eindeutig, weil das Projekt nur den
 * `web`-Guard verwendet (siehe `config/auth.php`). In einem Multi-Guard-Setup
 * (z. B. zusätzlicher `api`-Guard via Sanctum-Personal-Access-Tokens mit
 * eigenen Spatie-Rollen) kann derselbe Rollen-Name in mehreren Guards
 * existieren — der Log-Eintrag wäre dann forensisch nicht mehr eindeutig.
 * Vor Einführung eines weiteren Guards muss dieser Listener so erweitert
 * werden, dass `guard_name` mit ins Property-Set wandert.
 */
final class LogRoleChangeListener extends LogAuthorizationChangeListener
{
    public function handleAttached(RoleAttachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, ActivityEvent::ROLE_ATTACHED);
    }

    public function handleDetached(RoleDetachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, ActivityEvent::ROLE_DETACHED);
    }

    protected function activityChannel(): ActivityChannel
    {
        return ActivityChannel::ROLE;
    }

    protected function propertyKey(): string
    {
        return 'roles';
    }

    /**
     * @return class-string<Role>
     */
    protected function modelClass(): string
    {
        return Role::class;
    }

    protected function unknownIdsWarning(): string
    {
        return 'LogRoleChangeListener: unknown role IDs received';
    }
}
