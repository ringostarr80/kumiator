/**
 * @param {string} defaultName
 * @param {string} successMessage
 * @param {string} defaultErrorMessage
 */
globalThis.passkeyRegistration = function (defaultName, successMessage, defaultErrorMessage) {
    return {
        showForm: false,
        loading: false,
        credentialName: '',
        errorMessage: '',
        successMessage: '',

        async register() {
            this.errorMessage = '';
            this.successMessage = '';

            const name = this.credentialName.trim() || defaultName;

            this.loading = true;
            try {
                await globalThis.Passkeys.registerPasskey(name);
                this.successMessage = successMessage;
                this.showForm = false;
                this.credentialName = '';
                // Der Livewire-Komponente sagen, dass sie ihre Liste neu laden soll
                globalThis.Livewire.dispatch('passkey-registered');
            } catch (err) {
                // `err.message` bleibt bewusst außen vor: Schlägt schon der Browser fehl,
                // steht dort der englische DOMException-Text.
                const failure = /** @type {{ response?: { data?: { message?: string } } }} */ (err);
                this.errorMessage = failure.response?.data?.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};
