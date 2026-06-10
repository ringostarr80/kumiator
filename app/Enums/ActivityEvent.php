<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fachliche Event-Codes des Activity-Logs (Spatie `event`).
 *
 * Zentralisiert die zuvor verstreuten Magic-Strings (CLAUDE.md: Enums statt
 * Magic-Strings). Der `value` ist der stabile Maschinen-Code, der in der DB
 * landet und gegen den Reports/Tests filtern — er darf sich nicht ändern.
 *
 * {@see self::description()} leitet aus dem Code den Übersetzungs-Schlüssel ab
 * (`app.activity_<value>`) und ersetzt damit die bisher an jeder Schreib-Site
 * wiederholte `__('app.activity_...')`-Konstruktion. Jeder Case MUSS daher einen
 * passenden `activity_<value>`-Eintrag in `lang/{de,en}/app.php` haben — der
 * Unit-Test `ActivityEventTest` sichert das ab.
 *
 * Gruppiert nach Kanal ({@see ActivityChannel}); die Kanal-Zuordnung selbst
 * bleibt bewusst an der Schreib-Site (manche Vorgänge sind kanalübergreifend).
 */
enum ActivityEvent: string
{
    // auth
    case PASSWORD_LOGIN_SUCCEEDED = 'password_login_succeeded';
    case LOGOUT = 'logout';
    case LOGIN_FAILED = 'login_failed';
    case LOGIN_LOCKED_OUT = 'login_locked_out';
    case LOGIN_UNAPPROVED = 'login_unapproved';
    case OTHER_DEVICES_LOGGED_OUT = 'other_devices_logged_out';
    case OTHER_SESSIONS_LOGGED_OUT = 'other_sessions_logged_out';
    case PASSWORD_UPDATED = 'password_updated';
    case PASSWORD_RESET = 'password_reset';
    case PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    case PASSWORD_UPDATE_FAILED = 'password_update_failed';
    case PASSWORD_CONFIRMATION_FAILED = 'password_confirmation_failed';
    case EMAIL_VERIFIED = 'email_verified';
    case EMAIL_VERIFICATION_REQUESTED = 'email_verification_requested';
    case EMAIL_VERIFICATION_FAILED = 'email_verification_failed';
    case EMAIL_CHANGE_REQUESTED = 'email_change_requested';
    case EMAIL_CHANGED = 'email_changed';
    case EMAIL_CHANGE_CANCELLED = 'email_change_cancelled';
    case EMAIL_CHANGE_CONFIRMATION_REJECTED = 'email_change_confirmation_rejected';
    case EMAIL_CHANGE_REQUEST_FAILED = 'email_change_request_failed';
    case TWO_FA_ENABLED = '2fa_enabled';
    case TWO_FA_CONFIRMED = '2fa_confirmed';
    case TWO_FA_DISABLED = '2fa_disabled';
    case TWO_FA_SETUP_ABORTED = '2fa_setup_aborted';
    case TWO_FA_RECOVERY_CODES_REGENERATED = '2fa_recovery_codes_regenerated';
    case TWO_FA_RECOVERY_CODE_USED = '2fa_recovery_code_used';
    case TWO_FA_FAILED = '2fa_failed';
    case PASSKEY_LOGIN_FAILED = 'passkey_login_failed';

    // user
    case USER_CREATED = 'user_created';
    case USER_APPROVED = 'user_approved';
    case USER_RENAMED = 'user_renamed';
    case USER_DELETED = 'user_deleted';
    case USER_RESTORED = 'user_restored';
    case USER_SELF_REGISTERED = 'user_self_registered';
    case ACCOUNT_SELF_DELETED = 'account_self_deleted';
    case ACCOUNT_ADMIN_FORCE_DELETED = 'account_admin_force_deleted';
    case PROFILE_PHOTO_UPDATED = 'profile_photo_updated';
    case PROFILE_PHOTO_REMOVED = 'profile_photo_removed';
    case API_TOKEN_CREATED = 'api_token_created';
    case API_TOKEN_REVOKED = 'api_token_revoked';

    // passkey
    case PASSKEY_LOGIN_SUCCEEDED = 'passkey_login_succeeded';
    case PASSKEY_REGISTERED = 'passkey_registered';
    case PASSKEY_RENAMED = 'passkey_renamed';
    case PASSKEY_REMOVED = 'passkey_removed';
    case PASSKEY_REGISTRATION_FAILED = 'passkey_registration_failed';

    // security
    case AUTHORIZATION_DENIED = 'authorization_denied';
    case ACTIVITY_LOG_VIEWED = 'activity_log_viewed';

    // role
    case ROLE_CREATED = 'role_created';
    case ROLE_DELETED = 'role_deleted';
    case ROLE_ATTACHED = 'role_attached';
    case ROLE_DETACHED = 'role_detached';

    // permission
    case PERMISSION_ATTACHED = 'permission_attached';
    case PERMISSION_DETACHED = 'permission_detached';

    /**
     * Übersetzte Klartext-Beschreibung für die Activity-Log-UI. Der Schlüssel
     * folgt dem Schema `app.activity_<value>`. Fällt auf den rohen Code zurück,
     * falls die Übersetzung kein String ist (unmöglich für die flachen Keys —
     * der Zweig dient nur der Typ-Eingrenzung gegenüber `__()`'s
     * `string|array|null`-Rückgabe; kein `(string)`-Cast wegen PHPStan-max).
     */
    public function description(): string
    {
        $translation = __('app.activity_' . $this->value);

        return is_string($translation)
            ? $translation
            : $this->value;
    }
}
