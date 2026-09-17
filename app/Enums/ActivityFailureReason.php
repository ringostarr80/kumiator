<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Maschinen-Codes der `failure_reason`-Property im Activity-Log.
 *
 * Die Property beantwortet in der Forensik, woran ein Versuch scheiterte. Ihr
 * Wert steht als roher String in der Log-Zeile, und Schreiber wie Auswerter
 * müssen denselben treffen — ein Tippfehler an einer Schreibstelle erzeugt
 * einen Eintrag, den keine Abfrage mehr findet.
 */
enum ActivityFailureReason: string
{
    /** Das genannte Passwort passt nicht zum gespeicherten Hash. */
    case CURRENT_PASSWORD_MISMATCH = 'current_password_mismatch';

    /** Das Passwort stimmte, doch das Konto hat den Passwort-Login abgeschaltet. */
    case PASSWORD_LOGIN_DISABLED = 'password_login_disabled';

    /** Die WebAuthn-Zeremonie hat die Antwort des Browsers verworfen. */
    case VERIFICATION_FAILED = 'verification_failed';

    /**
     * Der Signaturzähler des Authenticators ist nicht gestiegen.
     *
     * `CheckCounter` steht am Ende der Zeremonie, hinter der Signaturprüfung:
     * Wer hier scheitert, besitzt den privaten Schlüssel und meldet trotzdem
     * einen bereits gesehenen Zählerstand — der Befund für einen geklonten
     * Authenticator oder einen wiedereingespielten Mitschnitt.
     */
    case COUNTER_INVALID = 'counter_invalid';

    /**
     * Die vorgelegte Credential-ID ist registriert, gehört aber einem anderen Konto
     * als dem, das den Versuch unternimmt.
     *
     * Ein Vorgang über Kontogrenzen hinweg, der sich zwischen abgebrochenen Dialogen
     * und verstümmelten Signaturen nicht verlieren darf. Ob die Zeremonie ihn schon
     * an der Liste der zugelassenen Credentials abweist oder erst später, ändert an
     * dem Befund nichts.
     */
    case CREDENTIAL_OWNER_MISMATCH = 'credential_owner_mismatch';

    /** Ein Fehler außerhalb der geprüften Pfade; begleitet von `report()`. */
    case INTERNAL_ERROR = 'internal_error';
}
