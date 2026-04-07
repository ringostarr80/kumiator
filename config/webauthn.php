<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Relying Party
    |--------------------------------------------------------------------------
    |
    | The Relying Party identifies this application to the authenticator.
    | The ID must match the effective domain (or a registrable domain suffix)
    | of the origin from which the WebAuthn ceremony is initiated.
    |
    */

    'relying_party' => [
        'name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'AssociationManager')),
        'id' => env('WEBAUTHN_RP_ID', null), // null = derived from request host
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | The time in milliseconds the browser will wait for a user interaction
    | before aborting the WebAuthn ceremony.
    |
    */

    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 60_000),

    /*
    |--------------------------------------------------------------------------
    | Attestation Conveyance
    |--------------------------------------------------------------------------
    |
    | Controls whether the authenticator should provide attestation.
    | 'none' is the recommended default for passkeys as it maximises
    | compatibility and does not expose device information.
    |
    | Possible values: 'none', 'indirect', 'direct', 'enterprise'
    |
    */

    'attestation_conveyance' => env('WEBAUTHN_ATTESTATION', 'none'),

    /*
    |--------------------------------------------------------------------------
    | User Verification
    |--------------------------------------------------------------------------
    |
    | Controls whether user verification (biometric / PIN) is required.
    | 'preferred' asks the authenticator to verify the user if it can.
    |
    | Possible values: 'required', 'preferred', 'discouraged'
    |
    */

    'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'preferred'),

];
