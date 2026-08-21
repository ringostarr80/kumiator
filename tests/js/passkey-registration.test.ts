import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Der Aufruf an den Authenticator lässt sich hier nicht echt ausführen — `navigator.credentials`
 * gibt es außerhalb des Browsers nicht. Geprüft wird deshalb allein, welche Meldung die Komponente
 * aus einem gescheiterten Aufruf macht.
 */

type RegistrationComponent = {
    errorMessage: string;
    showForm: boolean;
    register(): Promise<void>;
};

type RegistrationFactory = (
    defaultName: string,
    successMessage: string,
    defaultErrorMessage: string,
    statusMessages: Record<number, string>,
) => RegistrationComponent;

const DEFAULT_ERROR = 'Passkey-Registrierung fehlgeschlagen.';

const STATUS_MESSAGES = {
    419: 'Sitzung abgelaufen.',
    423: 'Passwortbestätigung abgelaufen.',
    429: 'Zu viele Versuche.',
};

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

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        await component.register();

        expect(component.errorMessage).toBe('Registrierungs-Sitzung abgelaufen.');
    });

    it('zeigt den übersetzten Standardtext statt der englischen Browser-Meldung', async () => {
        failRegistrationWith(new Error('The operation either timed out or was not allowed.'));

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        await component.register();

        expect(component.errorMessage).toBe(DEFAULT_ERROR);
    });

    it('ersetzt die Meldung, wenn der Text schon in der Middleware entsteht', async () => {
        failRegistrationWith({ response: { status: 423, data: { message: 'Password confirmation required.' } } });

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        await component.register();

        expect(component.errorMessage).toBe(STATUS_MESSAGES[423]);
    });

    it('klappt das Formular zu, wenn die Passwortbestätigung abgelaufen ist', async () => {
        failRegistrationWith({ response: { status: 423, data: { message: 'Password confirmation required.' } } });

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        component.showForm = true;
        await component.register();

        // Der Knopf, der die Bestätigung neu anstößt, ist erst wieder sichtbar,
        // wenn das Formular zu ist.
        expect(component.showForm).toBe(false);
    });

    it('lässt das Formular offen, wenn nur das Rate-Limit greift', async () => {
        failRegistrationWith({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        component.showForm = true;
        await component.register();

        expect(component.showForm).toBe(true);
    });

    it('lässt die Meldung des Servers bei einem Status ohne Übersetzung stehen', async () => {
        failRegistrationWith({ response: { status: 422, data: { message: 'Registrierungs-Sitzung abgelaufen.' } } });

        const component = createComponent('Mein Passkey', 'Angelegt.', DEFAULT_ERROR, STATUS_MESSAGES);
        await component.register();

        expect(component.errorMessage).toBe('Registrierungs-Sitzung abgelaufen.');
    });
});
