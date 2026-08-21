const HTTP_LOCKED = 423;

/**
 * @param {string} defaultName
 * @param {string} successMessage
 * @param {string} defaultErrorMessage
 * @param {Record<number, string>} statusMessages Übersetzungen je HTTP-Status
 */
globalThis.passkeyRegistration = function (defaultName, successMessage, defaultErrorMessage, statusMessages) {
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
                const failure =
                    /** @type {{ response?: { status?: number, data?: { message?: string } } }} */ (err);
                // Die Übersetzung schlägt die Meldung des Servers, weil Antworten aus der
                // Middleware einen im Framework fest verdrahteten englischen Text tragen.
                const response = failure.response;
                // Der Umweg ist Typ-Gymnastik: `statusMessages[response?.status]` lehnt
                // TypeScript ab, weil undefined kein Indextyp ist.
                const translated = response?.status === undefined
                    ? undefined
                    : statusMessages[response.status];

                this.errorMessage = translated ?? response?.data?.message ?? defaultErrorMessage;

                // Zugeklappt liegt der Knopf für die Passwortbestätigung wieder frei.
                // Sonst bliebe nur ein Neuladen, und jeder weitere Versuch zählte am
                // Rate-Limit mit, bis dieses die abgelaufene Bestätigung überdeckt.
                if (response?.status === HTTP_LOCKED) {
                    this.showForm = false;
                }
            } finally {
                this.loading = false;
            }
        },
    };
};
