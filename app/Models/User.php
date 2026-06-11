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
 * @property ?string $pending_email_confirm_token_hash
 * @property ?string $pending_email_cancel_token_hash
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
        'pending_email',
        'pending_email_cancel_token_hash',
        'pending_email_confirm_token_hash',
        'pending_email_sent_at',
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
     * Der WebAuthn-User-Handle muss über die Lebensdauer des Kontos stabil
     * bleiben und nach außen bedeutungslos sein — daher der unveränderliche
     * Integer-Primärschlüssel.
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
     * Hebt vor dem Insert das generische Eloquent-Event (`created`/`updated`/…)
     * auf den fachlichen `user_*`-Code an.
     *
     * Channel-agnostisch — dasselbe Mapping, egal ob CLI, Web-Admin oder
     * Self-Registration den Vorgang auslöste; den Auslöse-Kanal markieren
     * separate Hooks (`cli_actor`-Property, Self-Reg-Remap), nicht dieses Mapping.
     * Die Verdrahtung über einen `Activity::saving`-Listener ist am Hook im
     * `AppServiceProvider` begründet.
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
     * Der `updated`-Pfad kennt nur zwei fachliche Ausgänge: ein gesetztes
     * `approved_at` im Diff ist ein Approval, andernfalls eine Namensänderung
     * (`name` ist heute das einzige weitere geloggte, via `updated` änderbare
     * Feld). `deleted_at` taucht hier nicht auf — Soft-Deletes laufen via
     * Eloquent als eigenes `event = 'deleted'`/`'restored'`, nicht als `updated`.
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
