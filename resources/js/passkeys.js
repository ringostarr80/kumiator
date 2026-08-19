/**
 * WebAuthn-/Passkey-Helfer für das Frontend.
 *
 * Registrierung und Anmeldung laufen nach demselben Muster:
 *  1. Optionen vom Server holen (GET → JSON).
 *  2. `navigator.credentials.create` / `get` damit aufrufen.
 *  3. Die Browser-Antwort an den Server zurückschicken (POST → JSON).
 *
 * Die Umwandlung der Binärfelder (Base64URL ↔ ArrayBuffer) erledigen die
 * WebAuthn-Level-3-APIs selbst (parseCreationOptionsFromJSON,
 * parseRequestOptionsFromJSON, PublicKeyCredential.toJSON) — verfügbar ab
 * Chrome 129, Firefox 119 und Safari 18.
 */

/**
 * Registriert einen neuen Passkey für den angemeldeten Nutzer.
 *
 * @param {string} credentialName Vom Nutzer gewählter Name für diesen Passkey
 * @returns {Promise<object>}     Server-Antwort als JSON bei Erfolg
 * @throws {Error}                Bei WebAuthn- oder HTTP-Fehlern
 */
export async function registerPasskey(credentialName) {
    // 1. Creation-Optionen vom Server holen
    const optionsResponse = await globalThis.axios.get('/user/passkeys/register/options');
    const options = PublicKeyCredential.parseCreationOptionsFromJSON(optionsResponse.data);

    // 2. Den Authenticator ein neues Credential erzeugen lassen
    const credential = /** @type {PublicKeyCredential | null} */ (
        await navigator.credentials.create({ publicKey: options })
    );

    if (!credential) {
        throw new Error('No credential returned by the authenticator.');
    }

    // 3. Die Attestation-Antwort an den Server schicken
    const storeResponse = await globalThis.axios.post(
        '/user/passkeys/register',
        { ...credential.toJSON(), name: credentialName },
        { headers: { 'Content-Type': 'application/json' },
    });

    return storeResponse.data;
}

/**
 * Meldet mit einem Passkey an.
 *
 * @returns {Promise<string>} Redirect-URL bei Erfolg
 * @throws {Error}            Bei WebAuthn- oder HTTP-Fehlern
 */
export async function authenticateWithPasskey() {
    // 1. Request-Optionen vom Server holen
    const optionsResponse = await globalThis.axios.get('/passkeys/authenticate/options');
    const options = PublicKeyCredential.parseRequestOptionsFromJSON(optionsResponse.data);

    // 2. Den Authenticator um eine Assertion bitten
    const credential = /** @type {PublicKeyCredential | null} */ (
        await navigator.credentials.get({ publicKey: options })
    );

    if (!credential) {
        throw new Error('No credential returned by the authenticator.');
    }

    // 3. Die Assertion-Antwort an den Server schicken
    const authResponse = await globalThis.axios.post('/passkeys/authenticate', credential.toJSON(), {
        headers: { 'Content-Type': 'application/json' },
    });

    return authResponse.data.redirect;
}

// Auf `globalThis` gelegt, damit die x-data-Handler von Alpine.js sie ohne
// ES-Module-Import im Blade-Template aufrufen können.
globalThis.Passkeys = { registerPasskey, authenticateWithPasskey };
