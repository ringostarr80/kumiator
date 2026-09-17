/**
 * Alpine.js-Komponenten für die Bestätigung einer laufenden Sitzung per Passkey.
 *
 * Die übersetzten Ausweich-Fehlermeldungen kommen als Parameter herein, damit
 * die JS-Datei frei von serverseitiger Template-Logik bleibt.
 */

/**
 * @typedef {object} ConfirmableWire
 * @property {string | null} confirmableId
 * @property {() => void} stopConfirmingPassword
 */

/**
 * Livewire stellt `$wire` als Alpine-Magic bereit. Ein gleichnamiges Property im
 * Datenobjekt würde sie überdecken, deshalb steht der Zugriff hier statt in der
 * Komponente.
 *
 * @param {unknown} component
 * @returns {ConfirmableWire}
 */
function wireOf(component) {
    return /** @type {{ $wire: ConfirmableWire }} */ (component).$wire;
}

/**
 * Variante für den Livewire-Dialog im Profil.
 *
 * Nach geprüfter Assertion steht die Bestätigung bereits in der Sitzung. Hier
 * bleibt nur, den Dialog zu schließen und `password-confirmed` auszulösen — das
 * Event, auf das `x-confirms-password` ohnehin wartet, um die eigentliche Aktion
 * anzustoßen. Das Event ist reine Ablaufsteuerung: Ob die Aktion ausgeführt
 * werden darf, entscheidet serverseitig `ensurePasswordIsConfirmed()` anhand der
 * Sitzung.
 *
 * Ohne Argument bleibt die gemerkte Adresse der Middleware unangetastet: Sie
 * gehört der Seite, die noch auf ihre Bestätigung wartet.
 *
 * @param {string} defaultErrorMessage  Übersetzter Ausweichtext, falls der Server
 *                                      keine Meldung liefert.
 */
globalThis.passkeyConfirmation = function (defaultErrorMessage) {
    return {
        loading: false,
        errorMessage: '',

        async confirm() {
            this.errorMessage = '';
            this.loading = true;

            const wire = wireOf(this);

            // Vor dem Schließen lesen: `stopConfirmingPassword()` setzt die ID zurück.
            const confirmableId = wire.confirmableId;

            try {
                await globalThis.Passkeys.confirmWithPasskey();
                wire.stopConfirmingPassword();
                globalThis.dispatchEvent(
                    new CustomEvent('password-confirmed', { detail: { id: confirmableId } }),
                );
            } catch (err) {
                const failure = /** @type {{ response?: { data?: { message?: string } } }} */ (err);
                this.errorMessage = failure.response?.data?.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};

/**
 * Variante für die Vollseite, auf die die `password.confirm`-Middleware umleitet.
 * Das Ziel kennt nur der Server, weil allein er die ursprünglich angesteuerte
 * Adresse gespeichert hat.
 *
 * @param {string} defaultErrorMessage  Übersetzter Ausweichtext, falls der Server
 *                                      keine Meldung liefert.
 */
globalThis.passkeyConfirmationPage = function (defaultErrorMessage) {
    return {
        loading: false,
        errorMessage: '',

        async confirm() {
            this.errorMessage = '';
            this.loading = true;
            try {
                globalThis.location.href = await globalThis.Passkeys.confirmWithPasskey(true);
            } catch (err) {
                const failure = /** @type {{ response?: { data?: { message?: string } } }} */ (err);
                this.errorMessage = failure.response?.data?.message ?? defaultErrorMessage;
            } finally {
                this.loading = false;
            }
        },
    };
};
