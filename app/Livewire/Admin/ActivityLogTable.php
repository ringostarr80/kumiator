<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Activity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only Übersicht des Activity-Logs für Administratoren.
 *
 * Zugriffsschutz erfolgt zweistufig:
 *  - Primär über Route-Middleware `can:activity-log.view` (siehe routes/web.php),
 *    die den Request bereits vor dem Rendern der View abbricht und damit auch
 *    eventuelle Geschwister-Komponenten auf derselben Seite mit-schützt.
 *  - Defense-in-depth über `$this->authorize(...)` in `mount()`: greift, falls
 *    die Komponente jemals außerhalb der dedizierten Route eingebettet wird
 *    (eigene Route, andere Parent-Komponente) und der dort gesetzte Schutz
 *    versehentlich fehlt. Spatie-Permissions sind als Gate-Abilities registriert,
 *    die Prüfung ist deshalb identisch zur Route-Middleware.
 */
final class ActivityLogTable extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    private const int PER_PAGE = 25;

    public function mount(): void
    {
        $this->authorize('activity-log.view');
    }

    public function render(): View
    {
        return view('livewire.admin.activity-log-table', [
            'activities' => $this->loadActivities(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    private function loadActivities(): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['subject', 'causer'])
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }
}
