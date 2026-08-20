<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | Die Relying Party weist diese Anwendung gegenüber dem Authenticator aus.
    | Die ID muss der effektiven Domain (oder einem registrierbaren Suffix davon)
    | des Origins entsprechen, von dem die WebAuthn-Zeremonie ausgeht.
    |
    */

    'relying_party' => [
        'name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'Kumiator')),
        'id' => env('WEBAUTHN_RP_ID', null), // null = wird aus dem Request-Host abgeleitet
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Zeit in Millisekunden, die der Browser auf eine Nutzerinteraktion wartet,
    | bevor er die WebAuthn-Zeremonie abbricht.
    |
    */

    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 60_000),

    /*
    |--------------------------------------------------------------------------
    | Ceremony Session TTL
    |--------------------------------------------------------------------------
    |
    | Wie lange (in Sekunden) die serverseitigen Zeremonie-Optionen in der Session
    | gültig bleiben. Vollzieht der Nutzer die WebAuthn-Geste nicht in diesem
    | Fenster, gilt die abgelegte Challenge als abgelaufen und die Zeremonie muss
    | neu beginnen. Sollte etwas über `timeout` (Millisekunden, siehe oben) liegen,
    | um Netzwerklatenz aufzufangen.
    |
    */

    'ceremony_session_ttl' => (int) env('WEBAUTHN_CEREMONY_SESSION_TTL', 120),

    /*
    |--------------------------------------------------------------------------
    | Attestation Conveyance
    |--------------------------------------------------------------------------
    |
    | Steuert, ob der Authenticator eine Attestation liefern soll.
    | 'none' ist für Passkeys der empfohlene Standard: größte Kompatibilität,
    | und es werden keine Geräteinformationen preisgegeben.
    |
    | Mögliche Werte: 'none', 'indirect', 'direct', 'enterprise'
    |
    */

    'attestation_conveyance' => env('WEBAUTHN_ATTESTATION', 'none'),

];
