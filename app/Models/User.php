<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Contracts\MustBeApproved;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property ?string $pending_email_token_hash
 * @property ?\Illuminate\Support\Carbon $pending_email_sent_at
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $deleted_at
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
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
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
     * Returns a stable string identifier used as the WebAuthn user handle.
     * The handle is the integer primary key cast to string; it must be stable and opaque.
     */
    public function getWebAuthnUserHandle(): string
    {
        return (string)$this->id;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * @return HasMany<PasskeyCredential, $this>
     */
    public function passkeyCredentials(): HasMany
    {
        return $this->hasMany(PasskeyCredential::class);
    }

    /**
     * Activity-Log-Konfiguration.
     *
     * Geloggt werden nur fachlich relevante Felder — Secrets (Passwort, 2FA-Seed,
     * Recovery-Codes, Remember-Token) erscheinen NIEMALS im Log.
     *
     * `email_verified_at` wird bewusst NICHT geloggt: die E-Mail-Verifizierung
     * landet als dedizierter `email_verified`-Eintrag im `auth`-Log (siehe
     * `LogAuthenticationActivityListener::handleVerified()`). Ein zusätzlicher
     * generischer `user.updated`-Eintrag würde denselben Vorgang doppelt zählen.
     *
     * `email` wird aus demselben Grund NICHT geloggt: der zweistufige
     * Änderungsvorgang (Antrag → Bestätigung → Tausch) wird über drei
     * dedizierte `auth`-Channel-Events dokumentiert
     * (`email_change_requested`, `email_changed`, `email_change_cancelled`)
     * — siehe `App\Services\User\UserEmailChanger`. Wer die `email`-Spalte
     * außerhalb dieses Pfades direkt verändert, umgeht damit absichtlich den
     * Audit-Pfad; das ist heute nur in Tests/Seedern der Fall.
     *
     * Die `pending_email*`-Spalten sind ebenfalls NICHT in `logOnly`: ihr
     * Lebenszyklus ist vollständig über die `auth`-Events abgedeckt;
     * Audit-Inhalte am `user`-Channel wären redundant und würden Konflikte
     * mit der Hash-Speicherung des Tokens provozieren.
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
     * Mappt das generische Eloquent-Event eines User-Activity-Eintrags
     * (created/updated/deleted/restored) auf einen fachlichen Code
     * (user_created/user_approved/user_renamed/user_deleted/user_restored),
     * bevor der Eintrag gespeichert wird. Aufgerufen aus einem
     * `Activity::saving`-Listener im {@see \App\Providers\AppServiceProvider}.
     *
     * Hintergrund analog {@see PasskeyCredential::applyEventLabelToActivity}:
     * Der `LogsActivity`-Trait dieser Spatie-Version bietet keinen
     * `tapActivity`-Hook; ein globaler `saving`-Listener ist der einzige
     * stabile Punkt zwischen Trait-Setup und Insert. Die Mapping-Logik
     * gehört in die Domain, der Listener hängt nur dran.
     *
     * Channel-agnostisch: liefert dieselben fachlichen Codes unabhängig
     * davon, ob der Vorgang per CLI, Web-Admin (künftig) oder Web-Self-Reg
     * ausgelöst wurde. Der Auslöse-Kanal wird über das `cli_actor`-Property
     * (CLI) bzw. einen nachgelagerten `Activity::saving`-Hook
     * (Self-Registration → `user_self_registered`) gekennzeichnet.
     */
    public static function applyEventLabelToActivity(ActivityModel $activity): void
    {
        if ($activity->log_name !== ActivityChannel::USER->value) {
            return;
        }

        $event = $activity->event;

        if (!is_string($event)) {
            return;
        }

        $mapped = match ($event) {
            'created' => ActivityEvent::USER_CREATED,
            'updated' => self::mapUpdatedEvent($activity),
            'deleted' => ActivityEvent::USER_DELETED,
            'restored' => ActivityEvent::USER_RESTORED,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        $activity->event = $mapped->value;
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
            'password' => 'hashed',
        ];
    }

    /**
     * Unterscheidet einen Approval-Save (Setzen von `approved_at`) von einer
     * sonstigen Aktualisierung (heute realistisch nur `name`). `deleted_at`
     * wird via Eloquent als eigenes `event = 'deleted'`/`'restored'` geführt,
     * nicht als `updated` — daher hier keine Sonderbehandlung.
     *
     * `approved_at` schlägt jeden anderen Diff-Inhalt, weil ein kombinierter
     * Save (Approval + Namensänderung) fachlich als Approval-Vorgang dominiert.
     * Aktuell tritt diese Kombination im Code nirgends auf.
     *
     * Spatie legt den Attribut-Diff in `attribute_changes` ab (Collection mit
     * Sub-Keys `attributes` und `old`), NICHT in `properties` — letzteres ist
     * der Free-Form-Property-Bag (z. B. für unser `cli_actor`).
     */
    private static function mapUpdatedEvent(ActivityModel $activity): ActivityEvent
    {
        $changes = $activity->attribute_changes;
        $attributes = $changes?->get('attributes');

        if (is_array($attributes) && array_key_exists('approved_at', $attributes)) {
            return ActivityEvent::USER_APPROVED;
        }

        return ActivityEvent::USER_RENAMED;
    }
}
