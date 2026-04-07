/**
 * Alpine.js component for the passkey login button on the login page.
 *
 * The translated fallback error message is passed as a parameter so that
 * the JS file stays free of server-side template logic.
 *
 * Usage in Blade:
 *   <div x-data="passkeyLogin('{{ __('app.passkey_auth_error') }}')" ...>
 *
 * @param {string} defaultErrorMessage  Translated fallback shown when the
 *                                      server does not return a message.
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
                this.errorMessage = err.response?.data?.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};
