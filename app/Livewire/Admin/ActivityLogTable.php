<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Activity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only Übersicht des Activity-Logs für Administratoren.
 *
 * Zugriffsschutz erfolgt auf Route-Ebene (Middleware `can:activity-log.view`),
 * die Komponente selbst trifft keine Autorisierungsentscheidung mehr.
 */
final class ActivityLogTable extends Component
{
    use WithPagination;

    private const int PER_PAGE = 25;

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
