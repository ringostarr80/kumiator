/**
 * Alpine.js-Komponente für den Passkey-Button auf der Login-Seite.
 *
 * Die übersetzte Ausweich-Fehlermeldung kommt als Parameter herein, damit die
 * JS-Datei frei von serverseitiger Template-Logik bleibt.
 *
 * Einsatz in Blade:
 *   <div x-data="passkeyLogin('{{ __('app.passkey_auth_error') }}')" ...>
 *
 * @param {string} defaultErrorMessage  Übersetzter Ausweichtext, falls der Server
 *                                      keine Meldung liefert.
 */
globalThis.passkeyLogin = function (defaultErrorMessage) {
    return {
        loading: false,
        errorMessage: '',

        async authenticate() {
            this.errorMessage = '';
            this.loading = true;
            try {
                globalThis.location.href = await globalThis.Passkeys.authenticateWithPasskey();
            } catch (err) {
                const failure = /** @type {{ response?: { data?: { message?: string } } }} */ (err);
                this.errorMessage = failure.response?.data?.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};
