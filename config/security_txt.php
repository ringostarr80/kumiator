<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | security.txt (RFC 9116)
    |--------------------------------------------------------------------------
    |
    | Beide Werte setzt der Betreiber der Instanz. Fehlt einer, liefert die
    | Instanz unter `/.well-known/security.txt` nichts aus: RFC 9116 verlangt
    | beide Felder (Abschnitte 2.5.3 und 2.5.5), die Datei wäre sonst ungültig.
    |
    | `expires` ist ein fester Zeitpunkt und wird von Hand erneuert: Er
    | bestätigt, bis wann der Betreiber für die Angaben einsteht. Erlaubte
    | Formate und Pflege: `docs/operations.md`, Abschnitt „security.txt“.
    |
    */

    'contact' => env('SECURITY_TXT_CONTACT'),

    'expires' => env('SECURITY_TXT_EXPIRES'),

];
