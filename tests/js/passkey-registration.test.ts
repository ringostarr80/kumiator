import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Der Aufruf an den Authenticator lässt sich hier nicht echt ausführen — `navigator.credentials`
 * gibt es außerhalb des Browsers nicht. Geprüft wird deshalb allein, welche Meldung die Komponente
 * aus einem gescheiterten Aufruf macht.
 */

type RegistrationComponent = {
    errorMessage: string;
    register(): Promise<void>;
};

type RegistrationFactory = (
    defaultName: string,
    successMessage: string,
    defaultErrorMessage: string,
) => RegistrationComponent;

const DEFAULT_ERROR = 'Passkey-Registrierung fehlgeschlagen.';

let createComponent: RegistrationFactory;

function failRegistrationWith(reason: unknown): void {
    globalThis.Passkeys = {
        registerPasskey: () => Promise.reject(reason),
        authenticateWithPasskey: () => Promise.reject(reason),
    };
}

beforeAll(async () => {
    await import('../../resources/js/passkey-registration.js');
    createComponent = globalThis.passkeyRegistration as RegistrationFactory;
});

describe('passkeyRegistration', () => {
    it('zeigt die Meldung des Servers', async () => {
        failRegistrationWith({ response: { data: { message: 'Registrierungs-Sitzung abgelaufen.' } } });

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR);
        await component.register();

        expect(component.errorMessage).toBe('Registrierungs-Sitzung abgelaufen.');
    });

    it('zeigt den übersetzten Standardtext statt der englischen Browser-Meldung', async () => {
        failRegistrationWith(new Error('The operation either timed out or was not allowed.'));

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR);
        await component.register();

        expect(component.errorMessage).toBe(DEFAULT_ERROR);
    });
});
