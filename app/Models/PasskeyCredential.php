<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Facades\Activity;
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
     * Activity-Log-Konfiguration für die automatischen Eloquent-Lifecycle-Events
     * (created/updated/deleted). Geloggt werden ausschließlich `name` und
     * `aaguid` — Schlüsselmaterial (`credential_id`, `credential_public_key`,
     * `counter`, `transports`, `backup_eligible`, `backup_state`) bleibt ohne
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
            ->useLogName('passkey');
    }

    /**
     * Schreibt einen dedizierten Activity-Log-Eintrag für eine erfolgreiche
     * Passkey-Anmeldung. Aufzurufen aus `PasskeyAuthenticationService::verify()`,
     * unmittelbar nachdem `PasskeyCredentialRepository::updateAfterAuthentication()`
     * den Counter & `last_used_at` persistiert hat.
     *
     * Warum hier explizit (statt über den `LogsActivity`-Trait):
     *  - `event` wird auf `passkey_login_succeeded` gesetzt — der Eloquent-
     *    `updated` ist ein Implementierungsdetail, fachlich passiert ein Login.
     *    Der spezifische Code erlaubt scharfes Filtern/Reporting.
     *  - `description` ist übersetzt — die Activity-Log-UI zeigt damit
     *    "Passkey-Anmeldung erfolgreich" statt eines generischen "updated".
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

        Activity::useLog('passkey')
            ->event('passkey_login_succeeded')
            ->causedBy($owner)
            ->performedOn($this)
            ->log(__('app.activity_passkey_login_succeeded'));
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
}
