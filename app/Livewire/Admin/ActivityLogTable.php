<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Facades\Activity as ActivityLogger;

/**
 * Read-only Übersicht des Activity-Logs für Administratoren.
 *
 * Zugriffsschutz UND Audit sind bewusst in `mount()` gebündelt (statt über eine
 * Route-`can:`-Middleware), weil beides zusammengehört: eine Route-Middleware
 * würde den Request vor dem Mount abbrechen — der `authorization_denied`-Eintrag
 * entstünde dann nie. So wird auch der abgelehnte Zugriff protokolliert.
 *
 * Spatie-Permissions sind als Gate-Abilities registriert, daher greift
 * `Gate::denies('activity-log.view')` hier direkt.
 */
final class ActivityLogTable extends Component
{
    use WithPagination;

    private const int PER_PAGE = 25;

    /**
     * JSON-Flags fürs Pretty-Printing der Properties im Modal: unescaped
     * Slashes & Unicode, damit URLs/Umlaute lesbar bleiben; Exception statt
     * stillem `false` bei Encoding-Fehlern (z. B. invalides UTF-8).
     */
    private const int PRETTY_JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

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

            throw new AuthorizationException();
        }

        $this->recordAccessGranted();
    }

    /**
     * Folge-Requests (Filter, Sortierung, Pagination, Properties-Modal) laufen
     * ohne erneuten `mount()` — ohne diesen Hook überlebte eine bereits
     * geöffnete Seite den Permission-Entzug bis zum Page-Reload, und jeder
     * Wire-Request lädt über `render()` frische Daten. Abgelehnte Versuche
     * werden wie in `mount()` auditiert; der Zugriffs-Eintrag selbst bleibt
     * dagegen bewusst auf den Mount beschränkt (ein Eintrag pro Seitenaufruf).
     */
    public function hydrate(): void
    {
        if (Gate::denies('activity-log.view')) {
            $this->recordAuthorizationDenied();

            throw new AuthorizationException();
        }
    }

    /**
     * Wird absichtlich erst beim Klick aufgerufen, damit die initiale Page
     * nicht alle Properties-Blobs aller Rows der Seite an den Client schickt.
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
     * existierenden hohen Seite stehen.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'filter')) {
            $this->resetPage();
        }
    }

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
     * `subject`/`causer` sind `morphTo`: Eloquent feuert pro distinct Morph-Typ
     * auf der Seite eine eigene Query (nicht eine pauschale wie bei `belongsTo`).
     * Die Query-Zahl ist damit durch die Zahl der registrierten Morph-Typen
     * begrenzt (`Relation::enforceMorphMap()` in `AppServiceProvider::boot()`),
     * nicht durch die Row-Anzahl — kein N+1.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    private function loadActivities(): LengthAwarePaginator
    {
        // $sortDirection stammt aus dem client-kontrollierten Wire-State; ein
        // manipulierter Wert würde orderBy() mit InvalidArgumentException brechen.
        // Daher vor dem orderBy() auf ein gültiges Literal klemmen.
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
     * @param Builder<Activity> $query
     */
    private function applyFilters(Builder $query): void
    {
        $errors = $this->validateDateFilters();

        // Nur geprüfte Datumsgrenzen erreichen whereDate. Eine am Format
        // gescheiterte oder die Range verletzende Grenze wird übersprungen,
        // statt still zu einem Unsinns-Filter zu verpuffen.
        if ($this->filterDateFrom !== '' && !$errors->has('filterDateFrom')) {
            $query->whereDate('created_at', '>=', $this->filterDateFrom);
        }

        if ($this->filterDateTo !== '' && !$errors->has('filterDateTo')) {
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
     * Datumsfilter stammen über `#[Url]` auch als roher Query-String
     * (`?from=garbage`) und umgehen so den `type="date"`-Constraint des Inputs.
     * Ungeprüft verpuffen sie in `whereDate` still zu einem Unsinns-Filter;
     * daher hier prüfen und dem Admin als Fehler melden. Die Prüfung läuft im
     * Render-Pfad (nicht nur im `updated()`-Hook), damit auch beim Mount per URL
     * injizierte Werte erfasst werden.
     *
     * @return MessageBag die geprüften Filter-Fehler (auch auf der Komponente gesetzt)
     */
    private function validateDateFilters(): MessageBag
    {
        $validator = Validator::make(
            [
                'filterDateFrom' => $this->filterDateFrom ?: null,
                'filterDateTo' => $this->filterDateTo ?: null,
            ],
            [
                'filterDateFrom' => ['nullable', 'date_format:Y-m-d'],
                'filterDateTo' => ['nullable', 'date_format:Y-m-d'],
            ],
            ['date_format' => __('app.activity_log_filter_date_invalid')],
        );

        $errors = $validator->errors();

        // Range-Check erst, wenn beide Grenzen formal gültig sind — sonst meldeten
        // wir „Bis vor Von" für eine Grenze, die schon am Format scheitert. Beide
        // Werte sind dann valides Y-m-d, ihr String-Vergleich ist chronologisch.
        if (
            $errors->isEmpty()
            && $this->filterDateFrom !== ''
            && $this->filterDateTo !== ''
            && $this->filterDateTo < $this->filterDateFrom
        ) {
            $errors->add('filterDateTo', __('app.activity_log_filter_date_range'));
        }

        $this->setErrorBag($errors);

        return $errors;
    }

    /**
     * Sucht in `causer`/`subject` nach dem Namen. Die Typen werden als String-
     * Aliase übergeben (nicht als Klassennamen), damit diese Komponente nicht
     * direkt von Fremd-Paket-Klassen wie der Spatie-`Role` abhängt.
     *
     * `whereHasMorph` erzeugt EXISTS-Subqueries, also keine zusätzliche Query
     * pro Row — das Query-Budget bleibt gewahrt.
     *
     * @param Builder<Activity> $query
     */
    private function applyNameSearch(Builder $query, string $relation, string $term): void
    {
        // %/_ sind LIKE-Wildcards: ohne Escaping träfe `foo_bar` auch `fooXbar`
        // und `?causer=%` jede Zeile. SQLite kennt kein Default-Escape-Zeichen,
        // daher die explizite ESCAPE-Klausel (nur über whereRaw setzbar).
        $escaped = addcslashes($term, '\\%_');

        $query->whereHasMorph(
            $relation,
            ['user', 'passkey', 'role'],
            static function (Builder $related) use ($escaped): void {
                $related->whereRaw('name like ? escape ?', ['%' . $escaped . '%', '\\']);
            },
        );
    }

    /**
     * Steuert im Blade die Unterscheidung zwischen „Log ist leer" und „keine
     * Treffer für die Filterauswahl".
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
     * Das Activity-Log bündelt personenbezogene Daten aller Mitglieder (Namen,
     * Rollen-Zuweisungen, Login-Zeiten, IP-/E-Mail-Hashes); wer es wann einsieht,
     * ist nach Art. 5(2)/32 DSGVO (Rechenschaft + Nachvollziehbarkeit des Zugriffs
     * auf den Mitglieder-Audit-Trail) selbst dokumentationspflichtig — daher dieser
     * Eintrag beim Lese-Zugriff aufs UI.
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
     * Inline statt über eine statische Recorder-Methode auf einem Domain-Model —
     * für diese Ability gibt es schlicht kein passendes Domain-Objekt
     * (`activity-log.view` ist eine reine Anzeige-Permission).
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
