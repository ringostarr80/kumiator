<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Concerns\RemapsActivityEvent;
use App\Models\Contracts\AuthorizationAuditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Repräsentiert ein registriertes WebAuthn-/Passkey-Credential eines Nutzers.
 *
 * Das vollständige CredentialRecord aus der webauthn-lib wird als JSON
 * serialisiert in der Spalte `credential_public_key` abgelegt, damit das
 * Eloquent-Model von der internen Struktur der Library entkoppelt bleibt.
 *
 * @property string $id UUID-Primärschlüssel (über HasUuids).
 * @property int $user_id Fremdschlüssel auf die users-Tabelle.
 * @property string $credential_id Base64URL-kodierte (ohne Padding) rohe Credential-ID, wie vom
 *           Authenticator zurückgegeben.
 * @property string $credential_public_key Das vollständige, vom Symfony-Serializer der webauthn-lib
 *           als JSON serialisierte CredentialRecord.
 * @property int $counter Signatur-Zähler, den der Authenticator bei jeder Nutzung hochzählt.
 *           Der Server weist jede Assertion ab, deren Zähler nicht größer als der gespeicherte Wert ist
 *           (oder gleich 0 bei Plattform-Authenticatoren, die darauf verzichten) — erkennt geklonte Credentials.
 * @property array<int, string>|null $transports Bei der Registrierung gemeldete Transport-Hinweise
 *           des Authenticators (z. B. "internal", "usb", "nfc", "ble"). Füllen den
 *           PublicKeyCredentialDescriptor, damit der Browser den passenden Authenticator findet.
 *           Null, wenn der Authenticator keine Transports gemeldet hat.
 * @property bool $backup_eligible Ob das Credential so abgelegt ist, dass es gesichert und
 *           geräteübergreifend synchronisiert werden kann (CTAP-2.1-BE-Flag). True bei den meisten
 *           Plattform-Passkeys (iCloud-Schlüsselbund, Google Passwortmanager usw.), false bei Hardware-Keys.
 * @property bool $backup_state Ob das Credential aktuell gesichert/synchronisiert ist (CTAP-2.1-BS-Flag).
 *           Kann sich zwischen Authentifizierungen ändern, wenn sich der Sync-Status des Nutzers ändert.
 * @property string $aaguid Authenticator Attestation GUID — eine UUID, die Hersteller und Modell des
 *           Authenticators identifiziert (z. B. teilen sich alle YubiKey 5 NFC dieselbe AAGUID).
 *           Als UUID-String gespeichert. Nur Nullen ("00000000-0000-0000-0000-000000000000") bedeutet,
 *           dass der Authenticator sein Modell nicht preisgibt.
 * @property string $name Menschenlesbare Bezeichnung, die der Nutzer bei der Registrierung wählt
 *           (z. B. "iPhone", "MacBook", "YubiKey").
 * @property \Illuminate\Support\Carbon|null $last_used_at Zeitpunkt der letzten erfolgreichen Assertion.
 *           Null, wenn das Credential seit der Registrierung nie zur Authentifizierung genutzt wurde.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class PasskeyCredential extends Model implements AuthorizationAuditable
{
    /** @use HasFactory<\Database\Factories\PasskeyCredentialFactory> */
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use RemapsActivityEvent;

    protected $fillable = [
        'user_id',
        'credential_id',
        'credential_public_key',
        'counter',
        'transports',
        'backup_eligible',
        'backup_state',
        'aaguid',
        'name',
        'last_used_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `logOnly` ist eine Allowlist — alles andere, insbesondere sämtliches
     * Schlüsselmaterial, bleibt konstruktionsbedingt draußen.
     *
     * `name` steht in der Allowlist, obwohl der Klartext den Eintrag nie erreicht
     * (er fällt vor dem Insert aus dem Diff): Spatie wertet `dontLogEmptyChanges()`
     * schon beim Bauen des Eintrags aus. Ohne `name` in der Allowlist bliebe der
     * Diff einer Umbenennung leer und der `passkey_renamed`-Eintrag entfiele ganz —
     * das Anlegen und Entfernen von Zugangsmitteln soll aber nachweisbar bleiben.
     *
     * `last_used_at` fehlt bewusst, obwohl es eine reguläre Spalte ist: ein Login
     * aktualisiert nur `last_used_at` plus interne Secret-Felder und soll keinen
     * generischen `updated`-Eintrag erzeugen. Den Login dokumentiert stattdessen
     * ein dedizierter `passkey_login_succeeded`-Eintrag.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'aaguid'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(ActivityChannel::PASSKEY->value)
            ->setDescriptionForEvent(
                fn (string $eventName): string => self::mapLifecycleEvent($eventName)?->description() ?? $eventName,
            );
    }

    /**
     * Auf dem erfolgreichen Login-Pfad aufzurufen, nachdem die Signatur des
     * Authenticators verifiziert wurde. Bewusst manuell statt über den
     * `LogsActivity`-Trait, weil:
     *  - `event` wird auf `passkey_login_succeeded` gesetzt — der Eloquent-
     *    `updated` ist ein Implementierungsdetail, fachlich passiert ein Login.
     *    Der spezifische Code erlaubt scharfes Filtern/Reporting.
     *  - `description` ist übersetzt — die UI zeigt Klartext statt "updated".
     *  - `causedBy($this->user)` umgeht das Pre-Auth-Causer-Problem: zum
     *    Zeitpunkt der Verifikation ist `Auth::login()` noch nicht gelaufen,
     *    `auth()->user()` wäre `null`. Der Owner der Credential ist der
     *    eindeutige Akteur und wird hier explizit gesetzt.
     */
    public function recordSuccessfulLoginActivity(): void
    {
        $owner = $this->user;

        if ($owner === null) {
            return;
        }

        // Der Aufrufer hat den Login zu diesem Zeitpunkt schon committet. Ein
        // durchgereichter Insert-Fehler verkleidete ihn als 500: Der Browser
        // bekäme kein `redirect` und bliebe auf der Login-Seite stehen, obwohl
        // die Session steht. Melden statt werfen, wie auf den Failure-Pfaden.
        try {
            Activity::useLog(ActivityChannel::PASSKEY->value)
                ->event(ActivityEvent::PASSKEY_LOGIN_SUCCEEDED->value)
                ->causedBy($owner)
                ->performedOn($this)
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Wie der Passwort-`login_failed`-Pfad: anonyme Dritt-Daten, daher
     * `log_name=forensic` (verkürzte Retention dort begründet), ohne Causer und
     * ohne Subject (selbst bei gefundener Credential ist „Owner = Causer"
     * forensisch falsch — ein Angreifer könnte die Credential-ID gestohlen haben).
     *
     * Datenminimierung (DSGVO Art. 5 Abs. 1 lit. c): Klartext-`credential_id`
     * landet niemals im Log; stattdessen ein SHA-256-Hash über die vom
     * Browser gemeldete `rawId`/`id`. Der Hash erlaubt Korrelation gleicher
     * Angriffsversuche (z. B. wiederholte Counter-Mismatches als Hinweis
     * auf einen geklonten Authenticator), ohne die Credential-Referenz zu
     * duplizieren.
     *
     * Statisch, weil zum Failure-Zeitpunkt typischerweise gar keine
     * Passkey-Instanz existiert (Credential nicht gefunden / Verification-
     * Exception vor Resolve).
     *
     * Resilient gegen Activity-Log-Ausfälle: ein Schreibfehler wird still
     * gemeldet, statt den Auth-Pfad des Aufrufers zu unterbrechen.
     *
     * @param string $reason Stabiler Maschinen-Code des Fehlerpfads (`verification_failed`, `internal_error`).
     * @param string $rawBody Roh-Body des Authenticate-Requests.
     */
    public static function recordFailedLoginActivity(string $reason, string $rawBody): void
    {
        $properties = ['failure_reason' => $reason];

        $credentialIdHash = self::hashCredentialIdFromBody($rawBody);

        if ($credentialIdHash !== null) {
            $properties['credential_id_hash'] = $credentialIdHash;
        }

        try {
            Activity::useLog(ActivityChannel::FORENSIC->value)
                ->event(ActivityEvent::PASSKEY_LOGIN_FAILED->value)
                ->withProperties($properties)
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Gegenstück zum erfolgreichen Lifecycle-Pfad (`created` → `passkey_registered`):
     * gleicher Channel `passkey`, Causer ist der bereits eingeloggte User
     * (Registrier-Endpoint ist auth-pflichtig).
     *
     * Bewusst KEIN `performedOn`: der Vorgang ist genau das Scheitern, eine
     * Credential zu erzeugen — es existiert kein Subject. Ebenfalls bewusst
     * KEIN `credential_id_hash`: anders als beim Login-Failure-Pfad (Korrelation
     * wiederholter Versuche gegen dieselbe Credential-ID, Indikator für Klon-
     * Authentifikatoren) ist bei der Registration jede Credential-ID frisch —
     * eine Korrelation über Hashes brächte keinen forensischen Mehrwert.
     *
     * Statisch, weil zum Failure-Zeitpunkt keine Passkey-Instanz existiert
     * (Verification-Exception VOR Persistenz).
     *
     * Resilient gegen Activity-Log-Ausfälle: ein Schreibfehler wird still
     * gemeldet, statt den HTTP-Response-Pfad des Aufrufers zu unterbrechen.
     *
     * @param string $reason Stabiler Maschinen-Code des Fehlerpfads (`verification_failed`, `internal_error`).
     */
    public static function recordFailedRegistrationActivity(User $user, string $reason): void
    {
        try {
            Activity::useLog(ActivityChannel::PASSKEY->value)
                ->event(ActivityEvent::PASSKEY_REGISTRATION_FAILED->value)
                ->causedBy($user)
                ->withProperties(['failure_reason' => $reason])
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Der Credential-Name ist ein freies, vom Nutzer gefülltes Textfeld und trägt
     * erfahrungsgemäß Personenbezug („iPhone von Erika"). Aus dem Audit-Diff muss er
     * deshalb vor dem Insert wieder heraus: Der DSGVO-Purge des Hard-Deletes greift
     * über `subject_type`/`causer_type` auf den User, Passkey-Einträge tragen als
     * Subject aber die Credential. Sie überleben die Konto-Löschung — im
     * zweistufigen Admin-Pfad ist die Credential-Zeile zum Zeitpunkt des
     * Hard-Deletes sogar längst weg, der Eintrag also über gar nichts mehr
     * zuzuordnen und damit auch nachträglich nicht mehr zu bereinigen.
     *
     * `aaguid` bleibt: Hersteller/Modell des Authenticators sind gerätebezogen,
     * nicht personenbezogen, und tragen den Sicherheitswert des Eintrags.
     */
    public static function stripCredentialNameFromActivity(ActivityModel $activity): void
    {
        if ($activity->log_name !== ActivityChannel::PASSKEY->value) {
            return;
        }

        $changes = $activity->attribute_changes;

        if ($changes === null) {
            return;
        }

        $activity->attribute_changes = $changes->map(
            static fn (mixed $bag): mixed => is_array($bag) ? Arr::except($bag, ['name']) : $bag,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'transports' => 'array',
            'backup_eligible' => 'boolean',
            'backup_state' => 'boolean',
            'counter' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function activityRemapChannel(): string
    {
        return ActivityChannel::PASSKEY->value;
    }

    protected static function mapActivityEvent(string $eventName, ActivityModel $activity): ?ActivityEvent
    {
        return self::mapLifecycleEvent($eventName);
    }

    /**
     * Da `aaguid` post-create unveränderlich ist, kann `updated` realistisch nur
     * durch eine Namensänderung ausgelöst werden — daher `PASSKEY_RENAMED`.
     * `null` für Events ohne fachliches Pendant (kein Remapping/keine Description).
     */
    private static function mapLifecycleEvent(string $eventName): ?ActivityEvent
    {
        return match ($eventName) {
            'created' => ActivityEvent::PASSKEY_REGISTERED,
            'updated' => ActivityEvent::PASSKEY_RENAMED,
            'deleted' => ActivityEvent::PASSKEY_REMOVED,
            default => null,
        };
    }

    /**
     * Die vom Browser gemeldeten Werte (`rawId`/`id`) sind bereits Base64URL-
     * codiert (WebAuthn-Norm) — wir hashen die Repräsentation, nicht die
     * dekodierten Bytes, das reicht für die Korrelation gleicher Versuche.
     */
    private static function hashCredentialIdFromBody(string $rawBody): ?string
    {
        $decoded = json_decode($rawBody, associative: true);

        if (!is_array($decoded)) {
            return null;
        }

        $rawId = $decoded['rawId'] ?? $decoded['id'] ?? null;

        if (!is_string($rawId) || $rawId === '') {
            return null;
        }

        return hash('sha256', $rawId);
    }
}
