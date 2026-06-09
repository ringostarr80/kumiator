<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Represents a registered WebAuthn / Passkey credential for a user.
 *
 * The full PublicKeyCredentialSource from the webauthn-lib is serialised to
 * JSON and stored in the `credential_public_key` column so that the Eloquent
 * model stays decoupled from the library's internal structure.
 *
 * @property string $id UUID primary key (via HasUuids).
 * @property int $user_id Foreign key to the users table.
 * @property string $credential_id Base64URL-encoded (no padding) raw credential ID as returned
 *           by the authenticator.
 * @property string $credential_public_key The full PublicKeyCredentialSource serialised to JSON
 *           by the webauthn-lib Symfony serializer.
 * @property int $counter Signature counter incremented by the authenticator on every use.
 *           The server rejects any assertion whose counter is not greater than the stored value
 *           (or equal to 0 for platform authenticators that opt out), which detects cloned credentials.
 * @property array<int, string>|null $transports Authenticator transport hints reported during
 *           registration (e.g. "internal", "usb", "nfc", "ble"). Used to populate the
 *           PublicKeyCredentialDescriptor so the browser can find the right authenticator.
 *           Null when the authenticator did not report any transports.
 * @property bool $backup_eligible Whether the credential is stored in a way that allows it to
 *           be backed up and synced across devices (CTAP 2.1 BE flag). True for most platform
 *           passkeys (iCloud Keychain, Google Password Manager, etc.), false for hardware keys.
 * @property bool $backup_state Whether the credential is currently backed up / synced (CTAP 2.1
 *           BS flag). Can change between authentications as the user's sync state changes.
 * @property string $aaguid Authenticator Attestation GUID — a UUID that identifies the make and
 *           model of the authenticator (e.g. all YubiKey 5 NFC share the same AAGUID).
 *           Stored as a UUID string. All-zeros ("00000000-0000-0000-0000-000000000000") means
 *           the authenticator chose not to disclose its model.
 * @property string $name Human-readable label chosen by the user at registration time
 *           (e.g. "iPhone", "MacBook", "YubiKey").
 * @property \Illuminate\Support\Carbon|null $last_used_at Timestamp of the most recent successful
 *           assertion. Null if the credential has never been used for authentication after registration.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class PasskeyCredential extends Model
{
    /** @use HasFactory<\Database\Factories\PasskeyCredentialFactory> */
    use HasFactory;
    use HasUuids;
    use LogsActivity;

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
     * Gilt für die automatischen Eloquent-Lifecycle-Events (created/updated/deleted):
     * geloggt werden ausschließlich `name` und `aaguid` — Schlüsselmaterial
     * (`credential_public_key`, `credential_id`, `counter`, …) bleibt ohne
     * Ausnahme in der `passkey_credentials`-Tabelle.
     *
     * `last_used_at` ist bewusst NICHT in `logOnly`: Login-Updates bestehen
     * im Wesentlichen aus Secret-Feldern + `last_used_at` und sollen nicht
     * den generischen `event=updated`-Pfad triggern. Stattdessen schreibt
     * `recordSuccessfulLoginActivity()` einen dedizierten Eintrag mit
     * fachlichem `event`-Code (`passkey_login_succeeded`) und übersetzter
     * Description.
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
     * Schreibt einen dedizierten Activity-Log-Eintrag für eine erfolgreiche
     * Passkey-Anmeldung. Auf dem erfolgreichen Login-Pfad aufzurufen, nachdem
     * die Signatur des Authenticators verifiziert wurde.
     *
     * Warum hier explizit (statt über den `LogsActivity`-Trait):
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

        Activity::useLog(ActivityChannel::PASSKEY->value)
            ->event(ActivityEvent::PASSKEY_LOGIN_SUCCEEDED->value)
            ->causedBy($owner)
            ->performedOn($this)
            ->log(ActivityEvent::PASSKEY_LOGIN_SUCCEEDED->description());
    }

    /**
     * Wie der Passwort-`login_failed`-Pfad: unter `log_name=forensic`
     * (anonyme Dritt-Daten → verkürzte Retention, Art. 5(1)(e) DSGVO; siehe
     * {@see ActivityChannel::FORENSIC}), ohne Causer und ohne Subject (selbst
     * bei gefundener Credential ist „Owner = Causer" forensisch falsch — ein
     * Angreifer könnte die Credential-ID gestohlen haben).
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
                ->log(ActivityEvent::PASSKEY_LOGIN_FAILED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Gegenstück zum erfolgreichen Lifecycle-Pfad (`created` → `passkey_registered`):
     * gleicher Channel `passkey`, Causer ist der bereits eingeloggte User
     * (Registrier-Endpoint ist auth-pflichtig — `auth:sanctum + verified + approved`).
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
                ->log(ActivityEvent::PASSKEY_REGISTRATION_FAILED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Macht einen still abgelehnten Autorisierungs-Versuch auf eine Passkey-
     * Credential sichtbar: ohne diesen Eintrag bliebe der HTTP-403 unsichtbar
     * im Log — ein authentifizierter User probiert, eine fremde Credential
     * umzubenennen oder zu löschen, und nichts dokumentiert es.
     *
     * Channel `security`: bewusst NICHT `passkey`, weil der Eintrag kein
     * Lifecycle-Event ist, sondern eine Cross-Cutting-Auth-Verletzung.
     *
     * Datenminimierung (DSGVO Art. 5 Abs. 1 lit. c): die Klartext-UUID der
     * Ziel-Credential geht nicht ins Log, stattdessen ein SHA-256-Hash —
     * analog zum `credential_id_hash` bei Login-Failures. So bleibt
     * Korrelation wiederholter Versuche gegen dieselbe Credential möglich
     * (Indikator für gezielten Mass-Probing-Angriff), ohne den ID-Wert zu
     * duplizieren.
     *
     * Resilient gegen Activity-Log-Ausfälle: ein Schreibfehler darf den
     * Request-Pfad nicht beeinflussen — der ursprüngliche 403 muss raus.
     *
     * @param string $ability Spatie/Laravel-Ability-Name (`update`, `delete`).
     */
    public static function recordAuthorizationDeniedActivity(User $user, string $ability, self $target): void
    {
        try {
            Activity::useLog(ActivityChannel::SECURITY->value)
                ->event(ActivityEvent::AUTHORIZATION_DENIED->value)
                ->causedBy($user)
                ->withProperties([
                    'ability' => $ability,
                    'target_type' => 'passkey_credential',
                    'target_id_hash' => hash('sha256', $target->id),
                ])
                ->log(ActivityEvent::AUTHORIZATION_DENIED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Mappt das generische Eloquent-Event eines Passkey-Activity-Eintrags
     * (created/updated/deleted) auf einen fachlichen Code, bevor der Eintrag
     * gespeichert wird. Aufgerufen aus einem `Activity::saving`-Listener im
     * {@see \App\Providers\AppServiceProvider}.
     *
     * Hintergrund: Der `LogsActivity`-Trait persistiert das Event direkt via
     * `ActivityLogger::event()`. Ein globaler `Activity::saving`-Listener auf
     * dem Activity-Model ist daher die einzige Stelle, an der sich der Wert
     * nach Spatie-eigenem Setup, aber vor dem Insert anpassen lässt. Die
     * Mapping-Logik bleibt hier in der Domain, der Listener hängt nur dran.
     */
    public static function applyEventLabelToActivity(ActivityModel $activity): void
    {
        if ($activity->log_name !== ActivityChannel::PASSKEY->value) {
            return;
        }

        $event = $activity->event;

        if (!is_string($event)) {
            return;
        }

        $mapped = self::mapLifecycleEvent($event);

        if ($mapped === null) {
            return;
        }

        $activity->event = $mapped->value;
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

    /**
     * Mappt die Eloquent-Lifecycle-Events auf den fachlichen `ActivityEvent`.
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
     * `null`, wenn kein brauchbares Feld extrahierbar ist.
     */
    private static function hashCredentialIdFromBody(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }

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
