/**
 * WebAuthn / Passkey helpers for the AssociationManager front-end.
 *
 * Both the registration and authentication flows follow the same pattern:
 *  1. Fetch options from the server (GET → JSON).
 *  2. Call navigator.credentials.create / get with those options.
 *  3. Send the browser response back to the server (POST → JSON).
 *
 * Binary field conversion (Base64URL ↔ ArrayBuffer) is handled natively by
 * the WebAuthn Level 3 APIs (parseCreationOptionsFromJSON, parseRequestOptionsFromJSON,
 * PublicKeyCredential.toJSON), supported in Chrome 129+, Firefox 119+, Safari 18+.
 */

/**
 * Register a new passkey for the currently authenticated user.
 *
 * @param {string} credentialName User-chosen name for this passkey
 * @returns {Promise<object>}     Server response JSON on success
 * @throws {Error}                On WebAuthn or HTTP errors
 */
export async function registerPasskey(credentialName) {
    // 1. Fetch creation options from the server
    const optionsResponse = await globalThis.axios.get('/user/passkeys/register/options');
    const options = PublicKeyCredential.parseCreationOptionsFromJSON(optionsResponse.data);

    // 2. Ask the authenticator to create a new credential
    const credential = await navigator.credentials.create({ publicKey: options });

    if (!credential) {
        throw new Error('No credential returned by the authenticator.');
    }

    // 3. Send the attestation response to the server
    const storeResponse = await globalThis.axios.post('/user/passkeys/register', credential.toJSON(), {
        headers: { 'Content-Type': 'application/json' },
        params: { name: credentialName },
    });

    return storeResponse.data;
}

/**
 * Authenticate with a passkey.
 *
 * @param {string|null} email Optional e-mail to narrow the allowed credentials
 * @returns {Promise<string>} Redirect URL on success
 * @throws {Error}            On WebAuthn or HTTP errors
 */
export async function authenticateWithPasskey(email = null) {
    // 1. Fetch request options from the server
    const params = email ? { email } : {};
    const optionsResponse = await globalThis.axios.get('/passkeys/authenticate/options', { params });
    const options = PublicKeyCredential.parseRequestOptionsFromJSON(optionsResponse.data);

    // 2. Ask the authenticator for an assertion
    const credential = await navigator.credentials.get({ publicKey: options });

    if (!credential) {
        throw new Error('No credential returned by the authenticator.');
    }

    // 3. Send the assertion response to the server
    const authResponse = await globalThis.axios.post('/passkeys/authenticate', credential.toJSON(), {
        headers: { 'Content-Type': 'application/json' },
    });

    return authResponse.data.redirect;
}

// Expose on globalThis so Alpine.js x-data handlers can call these functions
// without needing ES module imports inside Blade template scripts.
globalThis.Passkeys = { registerPasskey, authenticateWithPasskey };
