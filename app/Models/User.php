<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Concerns\RemapsActivityEvent;
use App\Models\Contracts\MustBeApproved;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Activity as ActivityModel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $email
 * @property ?\Illuminate\Support\Carbon $email_verified_at
 * @property ?string $pending_email
 * @property ?string $pending_email_confirm_token_hash
 * @property ?string $pending_email_cancel_token_hash
 * @property ?\Illuminate\Support\Carbon $pending_email_sent_at
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $password_login_disabled_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
 * @property ?string $webauthn_user_handle Nullable trotz NOT-NULL-Spalte: Eine Teil-Selektion lädt sie nicht mit
 */
class User extends Authenticatable implements MustBeApproved, MustVerifyEmail
{
    use HasApiTokens;
    use HasRoles;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use LogsActivity;
    use Notifiable;
    use RemapsActivityEvent;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pending_email',
        'pending_email_cancel_token_hash',
        'pending_email_confirm_token_hash',
        'pending_email_sent_at',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'webauthn_user_handle',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Dieselbe Instanz wird im Lauf eines Requests mehrfach nach einem Passkey
     * gefragt; die Antwort wird je Instanz einmal geholt statt je Frage. Wer den
     * Stand der Zeile will, frischt auf — das leert auch das Gemerkte.
     */
    private ?bool $memoizedHasPasskey = null;

    /**
     * Der Handle wandert auf den Authenticator und bei synchronisierten Passkeys
     * in den Cloud-Dienst des Anbieters. Er muss über die Lebensdauer des Kontos
     * stabil bleiben: Ändert er sich, verwaisen alle registrierten Passkeys.
     *
     * Setzt eine gespeicherte, vollständig geladene Instanz voraus: Der Wert
     * entsteht erst im Insert, und eine Teil-Selektion lädt die Spalte nicht mit.
     * Ohne den Guard bliebe davon nur ein `TypeError` aus dem Rückgabetyp, der
     * die Ursache nicht benennt.
     */
    public function getWebAuthnUserHandle(): string
    {
        return $this->webauthn_user_handle
            ?? throw new MissingAttributeException($this, 'webauthn_user_handle');
    }

    /**
     * `usesUniqueIds` statt eines `creating`-Hooks, weil `Model::performInsert()`
     * `setUniqueIds()` noch vor dem Event-Dispatch aufruft: Der Wert der
     * NOT-NULL-Spalte entsteht damit auch dann, wenn der global geteilte
     * Model-Dispatcher ausgehängt ist — `Event::fake()`, `Model::withoutEvents()`,
     * `saveQuietly()` und der Seeder-Trait `WithoutModelEvents` tun genau das.
     */
    public function usesUniqueIds(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['webauthn_user_handle'];
    }

    /**
     * Base64URL ohne Padding, wie die Credential-IDs daneben. Von Hand statt über
     * die Bibliothek der Zeremonie, weil Models nicht von Vendor-Paketen außerhalb
     * der Allowlist abhängen dürfen.
     *
     * 32 Zufallsbytes ergeben 43 Zeichen. Mehr als 48 Bytes passen nicht: Darüber
     * überschreitet die Base64URL-Form die 64 Bytes, die die WebAuthn-Spezifikation
     * für `user.id` zulässt.
     */
    public function newUniqueId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isPasswordLoginDisabled(): bool
    {
        return $this->password_login_disabled_at !== null;
    }

    /**
     * Der Versand entfällt, sobald das Konto den Passwort-Login abgeschaltet hat: Ein
     * neu gesetztes Passwort öffnete sonst über den Postfachzugang wieder genau den
     * Weg, den die Abschaltung schließen soll.
     *
     * Unterdrückt wird der Versand, nicht die Anforderung: Würde `/forgot-password`
     * für solche Konten sichtbar anders antworten, verriete die Antwort einem
     * Unbeteiligten, dass zu dieser Adresse ein Konto mit Passkey existiert.
     *
     * `mixed` statt `string`, weil die Basissignatur untypisiert ist und PHP
     * Parametertypen nur erweitern, nicht verengen lässt.
     */
    public function sendPasswordResetNotification(mixed $token): void
    {
        if ($this->isPasswordLoginDisabled()) {
            return;
        }

        parent::sendPasswordResetNotification($token);
    }

    /**
     * Macht die (im `HasProfilePhoto`-Trait `protected`) Disk-Auflösung für die
     * Profilfoto-Action zugänglich, die das Schreiben/Löschen der Datei aus der
     * Lösch-Transaktion heraushebt — eine zweite Quelle der Wahrheit für die
     * Disk-Wahl entfällt damit.
     */
    public function profilePhotoDiskName(): string
    {
        return $this->profilePhotoDisk();
    }

    /**
     * @return HasMany<PasskeyCredential, $this>
     */
    public function passkeyCredentials(): HasMany
    {
        return $this->hasMany(PasskeyCredential::class);
    }

    public function hasPasskey(): bool
    {
        return $this->memoizedHasPasskey ??= $this->passkeyCredentials()->exists();
    }

    /**
     * Wer auffrischt, misstraut der Instanz und will den Stand der Zeile. Eine
     * gemerkte Antwort, die den Abgleich überlebt, machte daraus eine halbe — und
     * ausgerechnet der Griff dagegen träfe sie nicht.
     */
    public function refresh(): static
    {
        $this->memoizedHasPasskey = null;

        return parent::refresh();
    }

    /**
     * `logOnly` ist eine Allowlist — nur die drei genannten Felder landen im
     * `user`-Log; alles andere, insbesondere sämtliche Secrets, bleibt
     * konstruktionsbedingt draußen.
     *
     * `email_verified_at` fehlt bewusst: die E-Mail-Verifizierung landet als
     * dedizierter `email_verified`-Eintrag im `auth`-Log. Ein zusätzlicher
     * generischer `user.updated`-Eintrag würde denselben Vorgang doppelt zählen.
     *
     * `email` fehlt aus demselben Grund: der zweistufige Änderungsvorgang
     * (Antrag → Bestätigung → Tausch) wird über drei dedizierte `auth`-Events
     * dokumentiert (`email_change_requested`, `email_changed`,
     * `email_change_cancelled`) — Quelle: `App\Services\User\UserEmailChanger`.
     * Wer die `email`-Spalte außerhalb dieses Pfades direkt verändert, umgeht
     * den Audit-Pfad bewusst; das ist heute nur in Tests/Seedern der Fall.
     *
     * Die `pending_email*`-Spalten fehlen ebenfalls: ihr Lebenszyklus ist
     * vollständig über die `auth`-Events abgedeckt; ein `user`-Eintrag wäre
     * redundant und würde Token-Hashes ins Audit ziehen.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'approved_at', 'deleted_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(ActivityChannel::USER->value);
    }

    /**
     * Die kanonische Form der E-Mail-Identität. SQLites `NOCASE`-Collation
     * faltet ausschließlich ASCII A–Z, Fortify senkt Login-Eingaben dagegen
     * mb-basiert. Ohne eine hier festgelegte Normalform driften Schreib- und
     * Lesepfad bei jedem Nicht-ASCII-Buchstaben auseinander: der Login findet
     * die Adresse nicht mehr, und `MÜLLER@…`/`müller@…` stehen als zwei Konten
     * nebeneinander. `NOCASE` bleibt darunter als Netz, trägt die Garantie
     * aber nicht mehr.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Einstieg für jeden Lookup über eine von außen gelieferte Adresse — die
     * Spalte hält nur normalisierte Werte, ein roher Vergleich ginge daneben.
     *
     * @return Builder<static>
     */
    public static function queryByEmail(string $email): Builder
    {
        return static::query()->where('email', self::normalizeEmail($email));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'email_verified_at' => 'datetime',
            'pending_email_sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'password_login_disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Hält die Normalform auch auf den Pfaden ohne Validierung (Factory,
     * Seeder, CLI) und beim Tausch der bestätigten Adresse.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(static fn (string $value): string => self::normalizeEmail($value));
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function pendingEmail(): Attribute
    {
        return Attribute::set(
            static fn (?string $value): ?string => $value === null
                ? null
                : self::normalizeEmail($value),
        );
    }

    protected static function activityRemapChannel(): string
    {
        return ActivityChannel::USER->value;
    }

    protected static function mapActivityEvent(string $eventName, ActivityModel $activity): ?ActivityEvent
    {
        return match ($eventName) {
            'created' => ActivityEvent::USER_CREATED,
            'updated' => self::mapUpdatedEvent($activity),
            'deleted' => ActivityEvent::USER_DELETED,
            'restored' => ActivityEvent::USER_RESTORED,
            default => null,
        };
    }

    /**
     * `approved_at` im Diff ist ein Approval, `name` eine Umbenennung. Ein Diff
     * ohne beide (etwa eine direkte `deleted_at`-Zuweisung außerhalb des
     * SoftDeletes-`delete()`-Pfades) liefert `null`; dann bleibt der rohe
     * `updated`-Code stehen, statt den Vorgang als Umbenennung zu etikettieren.
     * So kann ein künftiges viertes `logOnly`-Feld nicht mehr still als
     * „umbenannt" durchrutschen — wer es fachlich labeln will, ergänzt hier
     * eine Zeile.
     *
     * `approved_at` schlägt `name`, weil ein kombinierter Save (Approval +
     * Namensänderung) fachlich als Approval-Vorgang dominiert. Aktuell tritt
     * diese Kombination im Code nirgends auf.
     *
     * Spatie legt den Attribut-Diff in `attribute_changes` ab (Collection mit
     * Sub-Keys `attributes` und `old`), NICHT in `properties` — letzteres ist
     * der Free-Form-Property-Bag (z. B. für unser `cli_actor`).
     */
    private static function mapUpdatedEvent(ActivityModel $activity): ?ActivityEvent
    {
        $changes = $activity->attribute_changes;
        $attributes = $changes?->get('attributes');

        if (!is_array($attributes)) {
            return null;
        }

        if (array_key_exists('approved_at', $attributes)) {
            return ActivityEvent::USER_APPROVED;
        }

        if (array_key_exists('name', $attributes)) {
            return ActivityEvent::USER_RENAMED;
        }

        return null;
    }
}
