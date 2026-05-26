<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Facades\Activity as ActivityLogger;

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

    /**
     * JSON-Flags fürs Pretty-Printing der Properties im Modal:
     * Einrückung + Klartext für Slashes & Unicode (sonst sind URLs/Umlaute
     * im Modal schwer lesbar) + Exception statt stillem `false` bei Encoding-
     * Fehlern (z. B. invalides UTF-8).
     */
    private const int PRETTY_JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    public bool $showPropertiesModal = false;

    public ?string $selectedProperties = null;

    public function mount(): void
    {
        if (Gate::denies('activity-log.view')) {
            $this->recordAuthorizationDenied();
        }

        $this->authorize('activity-log.view');
    }

    /**
     * Lädt die Properties einer Activity und öffnet das Modal.
     *
     * Wird absichtlich erst beim Klick aufgerufen, damit die initiale Page
     * nicht alle Properties-Blobs aller 25 Rows an den Client schickt
     * (Properties können bei Spatie-Diff-Events groß werden).
     *
     * Für Activities ohne Properties bleibt die Methode ein No-Op — das
     * UI blendet das Icon für solche Rows ohnehin aus, der Frühzeitig-
     * Exit ist Defense-in-Depth gegen manipulierte Wire-Calls.
     */
    public function showProperties(int $activityId): void
    {
        $activity = Activity::query()->whereKey($activityId)->first();
        $properties = $activity?->properties?->toArray() ?? [];

        if ($properties === []) {
            return;
        }

        $this->selectedProperties = json_encode($properties, self::PRETTY_JSON_FLAGS);
        $this->showPropertiesModal = true;
    }

    public function closeProperties(): void
    {
        $this->showPropertiesModal = false;
        $this->selectedProperties = null;
    }

    public function render(): View
    {
        return view('livewire.admin.activity-log-table', [
            'activities' => $this->loadActivities(),
        ]);
    }

    /**
     * Lädt die paginierte Activity-Liste mit Eager-Loading für `subject` & `causer`.
     *
     * Performance-Charakteristik:
     *  - 1 Query für die Pagination (Count + Select).
     *  - Pro distinct `subject_type` auf der Seite **eine zusätzliche** `whereIn`-
     *    Query (anders als ein normales `belongsTo` ist `morphTo` typ-abhängig:
     *    Eloquent gruppiert die Page-Rows nach Morph-Typ und feuert pro Typ eine
     *    eigene Query gegen die jeweilige Tabelle).
     *  - Pro distinct `causer_type` analog eine weitere Query.
     *
     * Bei aktuell zwei Loggable-Modellen (`user`, `passkey`) ergibt das im
     * Worst-Case ~5 Queries pro Seite, **unabhängig von der Anzahl der Rows** —
     * kein N+1. Der Test `testRenderingPageStaysWithinQueryBudget` schützt
     * gegen versehentliche Regressionen (z. B. wenn jemand das `with()` entfernt
     * oder im Blade-Template eine weitere Relation lazy lädt).
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    private function loadActivities(): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['subject', 'causer'])
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Schreibt einen Audit-Eintrag für den abgelehnten Zugriff auf das
     * Activity-Log-UI. Inline statt über statische Recorder-Methode auf einem
     * Domain-Model — es gibt für diese Ability schlicht kein passendes
     * Domain-Objekt (`activity-log.view` ist eine reine Anzeige-Permission).
     * Channel und Event-Code spiegeln das Schema aus
     * {@see \App\Models\PasskeyCredential::recordAuthorizationDeniedActivity()}.
     *
     * Resilient gegen Activity-Log-Ausfälle: der ursprüngliche 403 muss raus,
     * ein kaputter Audit-Pfad darf das nicht blockieren.
     */
    private function recordAuthorizationDenied(): void
    {
        $causer = Auth::user();

        if (!($causer instanceof User)) {
            return;
        }

        try {
            ActivityLogger::useLog('security')
                ->event('authorization_denied')
                ->causedBy($causer)
                ->withProperties([
                    'ability' => 'activity-log.view',
                    'target_type' => null,
                    'target_id_hash' => null,
                ])
                ->log(__('app.activity_authorization_denied'));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
