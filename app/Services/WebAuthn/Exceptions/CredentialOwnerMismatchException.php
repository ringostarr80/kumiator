<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Exceptions;

use Webauthn\Exception\AuthenticatorResponseVerificationException;

/**
 * Die Assertion hat die Zeremonie bestanden, gehört aber einem anderen Konto als
 * dem, das die Bestätigung verlangt.
 *
 * Untertyp der Verifikationsausnahme, weil die Antwort des Browsers damit
 * abgewiesen ist wie jede andere verworfene Assertion — ein Serverfehler ist es
 * nicht. Eigener Typ, weil der Aufrufer den Fall im Audit-Log benennen muss und
 * der Wortlaut einer Nachricht dafür kein Anker ist.
 */
final class CredentialOwnerMismatchException extends AuthenticatorResponseVerificationException
{
}
