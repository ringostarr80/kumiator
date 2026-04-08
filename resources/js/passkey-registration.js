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
                // Notify the Livewire PasskeyManagerForm to refresh its list
                globalThis.Livewire.dispatch('passkey-registered');
            } catch (err) {
                this.errorMessage = err.response?.data?.message ?? err.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};
