<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\AppTimezone;
use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\User;
use App\Services\Audit\Contracts\AuthorizationAuditorContract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
 * `Gate::denies(PermissionName::ACTIVITY_LOG_VIEW->value)` hier direkt.
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

    /**
     * Kanonisches Key-Set der Filter mit ihren inaktiven Werten (''). Einzige
     * Quelle der Wahrheit für den `$filters`-Default und `normalizeFilters()`,
     * damit das erwartete Key-Set nicht an zwei Stellen gepflegt wird.
     */
    private const array DEFAULT_FILTERS = [
        'dateFrom' => '',
        'dateTo' => '',
        'channel' => '',
        'event' => '',
        'causer' => '',
        'subject' => '',
    ];

    public string $sortDirection = 'desc';

    /**
     * Spaltenfilter (UND-verknüpft, leerer String = inaktiv) als ein Array, damit
     * `resetFilters()`, `hasActiveFilters()` und das Hinzufügen eines Filters aus
     * einer einzigen Quelle schöpfen. Per `#[Url]` in den Query-String gespiegelt
     * (`?filters[...]`), damit gefilterte Audit-Ansichten teil- und bookmarkbar
     * sind und einen Reload überstehen; leere Werte halten die URL sauber.
     *
     * @var array<string, string>
     */
    #[Url]
    public array $filters = self::DEFAULT_FILTERS;

    public bool $showPropertiesModal = false;

    public ?string $selectedProperties = null;

    private AuthorizationAuditorContract $authorizationAuditor;

    public function boot(AuthorizationAuditorContract $authorizationAuditor): void
    {
        $this->authorizationAuditor = $authorizationAuditor;
    }

    public function mount(): void
    {
        $this->denyUnlessAuthorized();

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
        $this->denyUnlessAuthorized();
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
        if (str_starts_with($name, 'filters.')) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('filters');
        $this->resetPage();
    }

    public function render(): View
    {
        $this->normalizeFilters();

        return view('livewire.admin.activity-log-table', [
            'activities' => $this->loadActivities(),
            'channels' => ActivityChannel::cases(),
            'events' => ActivityEvent::cases(),
            'hasActiveFilters' => $this->hasActiveFilters(),
        ]);
    }

    /**
     * Der Client kann das ganze `filters`-Property per Wire-Update ersetzen —
     * mit fehlenden Keys (`{"filters": {}}`) oder mit einem verschachtelten
     * Array pro Key (`?filters[causer][]=x`). Beides bräche die Seite mit 500:
     * `applyFilters()`/`validateDateFilters()` indexieren feste Keys (Undefined
     * array key), ein Array-Wert liefe in `applyNameSearch(string)`/`where()`.
     * Darum die Filter über das feste Default-Key-Set neu aufbauen: fehlende
     * Keys ergänzen, Nicht-Strings verwerfen, Fremd-Keys ignorieren — der
     * Default '' gilt durchweg als inaktiver Filter.
     */
    private function normalizeFilters(): void
    {
        $normalized = self::DEFAULT_FILTERS;

        foreach (array_keys($normalized) as $key) {
            $value = $this->filters[$key] ?? null;

            if (is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        $this->filters = $normalized;
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

        // created_at liegt in UTC vor, gefiltert wird aber nach Kalendertagen
        // der Anzeige-Zeitzone (so zeigt die Tabelle die Zeit). Daher die
        // lokale Tagesgrenze dort bilden und nach UTC umrechnen, statt den
        // UTC-Datumsteil zu vergleichen — sonst fiele ein Eintrag nahe
        // Mitternacht (Zonen-Offset) auf den Nachbartag. Nur geprüfte
        // Grenzen werden angewandt.
        if ($this->filters['dateFrom'] !== '' && !$errors->has('filters.dateFrom')) {
            $from = Carbon::parse($this->filters['dateFrom'], AppTimezone::DISPLAY->value)
                ->startOfDay()
                ->utc();
            $query->where('created_at', '>=', $from);
        }

        if ($this->filters['dateTo'] !== '' && !$errors->has('filters.dateTo')) {
            $to = Carbon::parse($this->filters['dateTo'], AppTimezone::DISPLAY->value)
                ->endOfDay()
                ->utc();
            $query->where('created_at', '<=', $to);
        }

        if ($this->filters['channel'] !== '') {
            $query->where('log_name', $this->filters['channel']);
        }

        if ($this->filters['event'] !== '') {
            $query->where('event', $this->filters['event']);
        }

        if ($this->filters['causer'] !== '') {
            $this->applyNameSearch($query, 'causer', $this->filters['causer']);
        }

        if ($this->filters['subject'] !== '') {
            $this->applyNameSearch($query, 'subject', $this->filters['subject']);
        }
    }

    /**
     * Datumsfilter stammen über `#[Url]` auch als roher Query-String
     * (`?filters[dateFrom]=garbage`) und umgehen so den `type="date"`-Constraint des Inputs.
     * Ungeprüft verpuffen sie still zu einem Unsinns-Filter; daher hier prüfen
     * und dem Admin als Fehler melden. Die Prüfung läuft im Render-Pfad (nicht
     * nur im `updated()`-Hook), damit auch beim Mount per URL injizierte Werte
     * erfasst werden.
     *
     * @return MessageBag die geprüften Filter-Fehler (auch auf der Komponente gesetzt)
     */
    private function validateDateFilters(): MessageBag
    {
        $validator = Validator::make(
            [
                'filters' => [
                    // `!== ''` statt `?:`: der falsy-, aber nicht-leere String
                    // `'0'` muss erhalten bleiben, damit `date_format` ihn als
                    // Fehler meldet — `?: null` ließe ihn als `null` durch die
                    // `nullable`-Regel rutschen und `applyFilters()` würfe dann
                    // `Carbon::parse('0')` ungefangen im Render-Pfad.
                    'dateFrom' => $this->filters['dateFrom'] !== '' ? $this->filters['dateFrom'] : null,
                    'dateTo' => $this->filters['dateTo'] !== '' ? $this->filters['dateTo'] : null,
                ],
            ],
            [
                'filters.dateFrom' => ['nullable', 'date_format:Y-m-d'],
                'filters.dateTo' => ['nullable', 'date_format:Y-m-d'],
            ],
            ['date_format' => __('app.activity_log_filter_date_invalid')],
        );

        $errors = $validator->errors();

        // Range-Check erst, wenn beide Grenzen formal gültig sind — sonst meldeten
        // wir „Bis vor Von" für eine Grenze, die schon am Format scheitert. Beide
        // Werte sind dann valides Y-m-d, ihr String-Vergleich ist chronologisch.
        if (
            $errors->isEmpty()
            && $this->filters['dateFrom'] !== ''
            && $this->filters['dateTo'] !== ''
            && $this->filters['dateTo'] < $this->filters['dateFrom']
        ) {
            $errors->add('filters.dateTo', __('app.activity_log_filter_date_range'));
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
     * Das führende Wildcard (`%term%`, s. u.) ist nicht indizierbar, aber
     * bewusst: Substring-Treffer sind gewolltes Admin-UX, und die EXISTS
     * korreliert über den PK der Instanz-Tabelle (ein Verein je Instanz →
     * klein), sodass das LIKE pro Zeile nur eine per PK geholte Row filtert
     * statt `users`/`passkeys`/`roles` zu scannen.
     *
     * @param Builder<Activity> $query
     */
    private function applyNameSearch(Builder $query, string $relation, string $term): void
    {
        // %/_ sind LIKE-Wildcards: ohne Escaping träfe `foo_bar` auch `fooXbar`
        // und `?causer=%` jede Zeile. SQLite kennt kein Default-Escape-Zeichen,
        // daher die explizite ESCAPE-Klausel (nur über whereRaw setzbar).
        $escaped = addcslashes($term, '\\%_');

        // Bewusst eine explizite Liste, nicht array_keys(morphMap()) oder '*':
        // die Closure sucht `name like` in jeder gelisteten Typ-Tabelle,
        // das sind nur Morph-Aliase mit `name`-Spalte. Ein künftiges
        // Morph-Model ohne `name` gehört hier nicht rein (sonst SQL-Fehler);
        // die Liste wird deshalb getrennt von der Morph-Map gepflegt.
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
        return array_any($this->filters, fn (string $value): bool => $value !== '');
    }

    private function denyUnlessAuthorized(): void
    {
        if (Gate::denies(PermissionName::ACTIVITY_LOG_VIEW->value)) {
            $this->authorizationAuditor->recordSubjectlessDenial(
                Auth::user(),
                PermissionName::ACTIVITY_LOG_VIEW->value,
            );

            throw new AuthorizationException();
        }
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
}
