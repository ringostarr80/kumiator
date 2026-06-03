<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Facades\Activity as ActivityLogger;

/**
 * Read-only Übersicht des Activity-Logs für Administratoren.
 *
 * Zugriffsschutz UND Audit sind bewusst in `mount()` gebündelt (statt über eine
 * Route-`can:`-Middleware), weil beides zusammengehört: der abgelehnte Zugriff
 * muss protokolliert werden. Eine Route-Middleware würde den Request vor dem
 * Mount abbrechen — der `authorization_denied`-Eintrag entstünde dann nie. In
 * `mount()` läuft daher in einem Durchgang:
 *  - `Gate::denies(...)` → `recordAuthorizationDenied()` (Channel `security`),
 *  - `$this->authorize(...)` → 403 bei fehlender Permission,
 *  - `recordAccessGranted()` → `activity_log_viewed` (Channel `security`) bei Erfolg.
 *
 * Spatie-Permissions sind als Gate-Abilities registriert; die Prüfung greift
 * auch dann, wenn die Komponente künftig außerhalb der dedizierten Route
 * eingebettet würde.
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

    /**
     * Sortierrichtung der Zeitpunkt-Spalte; Default `desc` = neueste zuerst,
     * deckt sich mit der bisherigen Standard-Reihenfolge (`latest('id')`).
     */
    public string $sortDirection = 'desc';

    /**
     * Spaltenfilter (UND-verknüpft). Leerer String = inaktiv. Per `#[Url]` in
     * den Query-String gespiegelt, damit gefilterte Audit-Ansichten teil- und
     * bookmarkbar sind und einen Reload überstehen; der leere Default hält die
     * URL sauber, solange nicht gefiltert wird.
     */
    #[Url(as: 'from')]
    public string $filterDateFrom = '';

    #[Url(as: 'to')]
    public string $filterDateTo = '';

    #[Url(as: 'channel')]
    public string $filterChannel = '';

    #[Url(as: 'event')]
    public string $filterEvent = '';

    #[Url(as: 'causer')]
    public string $filterCauser = '';

    #[Url(as: 'subject')]
    public string $filterSubject = '';

    public bool $showPropertiesModal = false;

    public ?string $selectedProperties = null;

    public function mount(): void
    {
        if (Gate::denies('activity-log.view')) {
            $this->recordAuthorizationDenied();
        }

        $this->authorize('activity-log.view');

        $this->recordAccessGranted();
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

    /**
     * Toggelt die Sortierrichtung der Zeitpunkt-Spalte und springt zurück auf
     * Seite 1: bei umgekehrter Richtung ist die alte Seitennummer inhaltlich
     * bedeutungslos, der Nutzer erwartet den Anfang der neu sortierten Liste.
     */
    public function sortByCreatedAt(): void
    {
        $this->sortDirection = $this->sortDirection === 'desc'
            ? 'asc'
            : 'desc';
        $this->resetPage();
    }

    /**
     * Jede Filter-Änderung muss zurück auf Seite 1 — sonst bliebe der Page-
     * Cursor auf einer in der gefilterten Treffermenge oft nicht mehr
     * existierenden hohen Seite stehen. Greift für alle `filter*`-Properties.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

    /**
     * Setzt alle Spaltenfilter auf den Ausgangszustand zurück und springt auf
     * Seite 1.
     */
    public function resetFilters(): void
    {
        $this->reset([
            'filterDateFrom',
            'filterDateTo',
            'filterChannel',
            'filterEvent',
            'filterCauser',
            'filterSubject',
        ]);
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.activity-log-table', [
            'activities' => $this->loadActivities(),
            'channels' => ActivityChannel::cases(),
            'events' => ActivityEvent::cases(),
            'hasActiveFilters' => $this->hasActiveFilters(),
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
        // Manipulierte Wire-Payloads könnten $sortDirection beliebig setzen;
        // vor dem orderBy() auf ein hartes Literal klemmen — verhindert
        // sowohl SQL-Injection als auch die InvalidArgumentException, die
        // orderBy() bei ungültiger Richtung würfe.
        $direction = $this->sortDirection === 'asc'
            ? 'asc'
            : 'desc';

        $query = Activity::query()->with(['subject', 'causer']);

        $this->applyFilters($query);

        return $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE);
    }

    /**
     * Hängt die aktiven Spaltenfilter (UND-verknüpft) an die Query; leere
     * Filter werden übersprungen.
     *
     * `causer`/`subject` sind polymorph (`user`/`passkey`/`role`); die Namens-
     * suche läuft über `whereHasMorph` mit einer für alle drei Typen gültigen
     * Closure — alle haben eine `name`-Spalte. Das erzeugt EXISTS-Subqueries,
     * aber keine zusätzliche Query pro Row; das Query-Budget bleibt gewahrt.
     *
     * @param Builder<Activity> $query
     */
    private function applyFilters(Builder $query): void
    {
        if ($this->filterDateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->filterDateFrom);
        }

        if ($this->filterDateTo !== '') {
            $query->whereDate('created_at', '<=', $this->filterDateTo);
        }

        if ($this->filterChannel !== '') {
            $query->where('log_name', $this->filterChannel);
        }

        if ($this->filterEvent !== '') {
            $query->where('event', $this->filterEvent);
        }

        if ($this->filterCauser !== '') {
            $this->applyNameSearch($query, 'causer', $this->filterCauser);
        }

        if ($this->filterSubject !== '') {
            $this->applyNameSearch($query, 'subject', $this->filterSubject);
        }
    }

    /**
     * Namenssuche über eine polymorphe Relation (`causer`/`subject`). Die
     * Morph-Typen werden als Aliase übergeben, damit die Komponente nicht auf
     * Vendor-Modellklassen (z. B. `Spatie\Permission\Models\Role`) angewiesen
     * ist — die Architektur-Regel hält Livewire-Komponenten von Vendor-
     * Namespaces fern.
     *
     * @param Builder<Activity> $query
     */
    private function applyNameSearch(Builder $query, string $relation, string $term): void
    {
        $query->whereHasMorph(
            $relation,
            ['user', 'passkey', 'role'],
            static function (Builder $related) use ($term): void {
                $related->where('name', 'like', '%' . $term . '%');
            },
        );
    }

    /**
     * Ob aktuell überhaupt gefiltert wird — steuert im Blade die Unterscheidung
     * zwischen „Log ist leer" und „keine Treffer für die Filterauswahl".
     */
    private function hasActiveFilters(): bool
    {
        return $this->filterDateFrom !== ''
            || $this->filterDateTo !== ''
            || $this->filterChannel !== ''
            || $this->filterEvent !== ''
            || $this->filterCauser !== ''
            || $this->filterSubject !== '';
    }

    /**
     * Schreibt einen Audit-Eintrag für den erfolgreichen Lese-Zugriff auf das
     * Activity-Log-UI. Das Log bündelt personenbezogene Daten aller Mitglieder
     * (Namen, Rollen-Zuweisungen, Login-Zeiten, IP-/E-Mail-Hashes); wer es wann
     * einsieht, ist nach Art. 5(2)/32 DSGVO (Rechenschaft + Nachvollziehbarkeit
     * des Zugriffs auf den Mitglieder-Audit-Trail) selbst dokumentationspflichtig.
     *
     * Anders als bei `authorization_denied` ist der Causer hier zwingend zu
     * benennen — ein Lese-Zugriff ist nur dann sinnvoll auditierbar, wenn der
     * einsehende Admin identifiziert wird.
     *
     * Granularität: `mount()` läuft pro Livewire-Lebenszyklus genau einmal;
     * Pagination und das Properties-Modal re-hydrieren die bestehende Instanz
     * ohne erneuten Mount und erzeugen daher keinen zusätzlichen Eintrag. Es
     * entsteht ein Eintrag pro Seitenaufruf, nicht pro Zeile.
     *
     * Resilient gegen Activity-Log-Ausfälle: ein kaputter Audit-Pfad darf das
     * Anzeigen des Logs nicht blockieren.
     */
    private function recordAccessGranted(): void
    {
        $causer = Auth::user();

        if (!($causer instanceof User)) {
            return;
        }

        try {
            ActivityLogger::useLog(ActivityChannel::SECURITY->value)
                ->event(ActivityEvent::ACTIVITY_LOG_VIEWED->value)
                ->causedBy($causer)
                ->log(ActivityEvent::ACTIVITY_LOG_VIEWED->description());
        } catch (\Throwable $e) {
            report($e);
        }
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
            ActivityLogger::useLog(ActivityChannel::SECURITY->value)
                ->event(ActivityEvent::AUTHORIZATION_DENIED->value)
                ->causedBy($causer)
                ->withProperties([
                    'ability' => 'activity-log.view',
                    'target_type' => null,
                    'target_id_hash' => null,
                ])
                ->log(ActivityEvent::AUTHORIZATION_DENIED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
