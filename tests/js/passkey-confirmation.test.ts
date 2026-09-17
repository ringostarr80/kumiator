import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Der Aufruf an den Authenticator lässt sich hier nicht echt ausführen —
 * `navigator.credentials` gibt es außerhalb des Browsers nicht. Geprüft wird die
 * Ablaufsteuerung um ihn herum: welches Event die Komponente auslöst, in welcher
 * Reihenfolge sie die Kennung liest, und was sie aus einem Fehlschlag macht.
 */

type ConfirmationComponent = {
    errorMessage: string;
    loading: boolean;
    $wire: { confirmableId: string | null; stopConfirmingPassword(): void };
    confirm(): Promise<void>;
};

type ConfirmationFactory = (defaultErrorMessage: string) => ConfirmationComponent;

const DEFAULT_ERROR = 'Die Bestätigung mit dem Passkey ist fehlgeschlagen.';
const CONFIRMABLE_ID = 'd41d8cd98f00b204e9800998ecf8427e';

let createComponent: ConfirmationFactory;
let createPageComponent: ConfirmationFactory;
let dispatched: CustomEvent[];
let confirmArguments: (boolean | undefined)[];

function withConfirmationResult(result: () => Promise<string>): void {
    confirmArguments = [];
    globalThis.Passkeys = {
        registerPasskey: () => Promise.reject(new Error('not used')),
        authenticateWithPasskey: () => Promise.reject(new Error('not used')),
        confirmWithPasskey: (wantsRememberedTarget?: boolean) => {
            confirmArguments.push(wantsRememberedTarget);

            return result();
        },
    };
}

/**
 * `$wire` stellt Livewire im Browser bereit; hier steht ein Doppel dafür, das
 * die Kennung wie das Original beim Schließen zurücksetzt.
 */
function wireDouble(): ConfirmationComponent['$wire'] {
    return {
        confirmableId: CONFIRMABLE_ID,
        stopConfirmingPassword() {
            this.confirmableId = null;
        },
    };
}

beforeAll(async () => {
    await import('../../resources/js/passkey-confirmation.js');
    createComponent = globalThis.passkeyConfirmation as ConfirmationFactory;
    createPageComponent = globalThis.passkeyConfirmationPage as ConfirmationFactory;
});

beforeEach(() => {
    dispatched = [];
    // Node kennt kein `globalThis.location`; ohne das Doppel schlüge die
    // Zuweisung fehl und landete stumm im Fehlerzweig der Komponente.
    globalThis.location = { href: '' } as Location;
    // Node kennt kein `globalThis.dispatchEvent`; das Doppel hält fest, was die
    // Komponente ausgelöst hätte.
    globalThis.dispatchEvent = vi.fn((event: Event) => {
        dispatched.push(event as CustomEvent);

        return true;
    }) as typeof globalThis.dispatchEvent;
});

describe('passkeyConfirmation', () => {
    it('löst die Aktion mit der Kennung aus, die vor dem Schließen galt', async () => {
        withConfirmationResult(() => Promise.resolve('/dashboard'));

        const component = createComponent(DEFAULT_ERROR);
        component.$wire = wireDouble();
        await component.confirm();

        expect(dispatched).toHaveLength(1);
        expect(dispatched[0].type).toBe('password-confirmed');
        // Ohne das vorherige Auslesen käme hier `null` an, und die Aktion, auf die
        // `x-confirms-password` wartet, liefe nie los.
        expect(dispatched[0].detail).toEqual({ id: CONFIRMABLE_ID });
    });

    it('schließt den Dialog', async () => {
        withConfirmationResult(() => Promise.resolve('/dashboard'));

        const component = createComponent(DEFAULT_ERROR);
        component.$wire = wireDouble();
        await component.confirm();

        expect(component.$wire.confirmableId).toBeNull();
        expect(component.loading).toBe(false);
    });

    it('fordert das gemerkte Ziel nicht an', async () => {
        withConfirmationResult(() => Promise.resolve('/dashboard'));

        const component = createComponent(DEFAULT_ERROR);
        component.$wire = wireDouble();
        await component.confirm();

        // Der Server zieht das Ziel beim Anfordern aus der Sitzung; eine parallel
        // wartende Bestätigungsseite verlöre damit ihres.
        expect(confirmArguments).toEqual([undefined]);
    });

    it('löst nichts aus, wenn die Assertion scheitert', async () => {
        withConfirmationResult(() => Promise.reject({ response: { data: { message: 'Abgelaufen.' } } }));

        const component = createComponent(DEFAULT_ERROR);
        component.$wire = wireDouble();
        await component.confirm();

        expect(dispatched).toHaveLength(0);
        expect(component.errorMessage).toBe('Abgelaufen.');
    });

    it('zeigt den übersetzten Standardtext statt der englischen Browser-Meldung', async () => {
        withConfirmationResult(() => Promise.reject(new Error('The operation was not allowed.')));

        const component = createComponent(DEFAULT_ERROR);
        component.$wire = wireDouble();
        await component.confirm();

        expect(component.errorMessage).toBe(DEFAULT_ERROR);
    });
});

describe('passkeyConfirmationPage', () => {
    it('folgt dem Ziel, das der Server nennt', async () => {
        withConfirmationResult(() => Promise.resolve('/user/passkeys/register/options'));

        const component = createPageComponent(DEFAULT_ERROR);
        await component.confirm();

        expect(globalThis.location.href).toBe('/user/passkeys/register/options');
    });

    it('fordert das gemerkte Ziel an', async () => {
        withConfirmationResult(() => Promise.resolve('/user/passkeys/register/options'));

        const component = createPageComponent(DEFAULT_ERROR);
        await component.confirm();

        expect(confirmArguments).toEqual([true]);
    });

    it('zeigt die Meldung des Servers, statt die Seite zu wechseln', async () => {
        withConfirmationResult(() => Promise.reject({ response: { data: { message: 'Abgelaufen.' } } }));

        const component = createPageComponent(DEFAULT_ERROR);
        await component.confirm();

        expect(component.errorMessage).toBe('Abgelaufen.');
    });

    it('zeigt den übersetzten Standardtext statt der englischen Browser-Meldung', async () => {
        withConfirmationResult(() => Promise.reject(new Error('The operation was not allowed.')));

        const component = createPageComponent(DEFAULT_ERROR);
        await component.confirm();

        expect(component.errorMessage).toBe(DEFAULT_ERROR);
    });
});
