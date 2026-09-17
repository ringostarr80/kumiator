import { beforeEach, describe, expect, it } from 'vitest';
import { authenticateWithPasskey, confirmWithPasskey, registerPasskey } from '../../resources/js/passkeys.js';

/**
 * `axios`, `PublicKeyCredential` und `navigator.credentials` gibt es außerhalb des
 * Browsers nicht; hier stehen Double dafür, die festhalten, was die Funktionen ihnen
 * reichen. Geprüft wird der Weg der Daten: welche Adressen sie rufen, was sie dem
 * Authenticator geben und was sie an den Server zurückschicken.
 */

const OPTIONS_JSON = { challenge: 'Y2hhbGxlbmdl' };
const PARSED_CREATION_OPTIONS = { challenge: new Uint8Array([1, 2, 3]) } as unknown as PublicKeyCredentialCreationOptions;
const PARSED_REQUEST_OPTIONS = { challenge: new Uint8Array([4, 5, 6]) } as unknown as PublicKeyCredentialRequestOptions;
const CREDENTIAL_JSON = { id: 'cred-1', type: 'public-key' };
const CREDENTIAL = { toJSON: () => CREDENTIAL_JSON } as unknown as PublicKeyCredential;
const JSON_HEADERS = { headers: { 'Content-Type': 'application/json' } };
const STORED_PASSKEY = { id: 7, name: 'Mein Passkey', created_at: '2026-09-17T15:00:00+02:00' };

let requestedUrls: string[];
let posted: { url: string; data: unknown; config: unknown }[];
let serverAnswer: unknown;
let parsedFrom: unknown[];
let createdWith: CredentialCreationOptions[];
let askedFor: CredentialRequestOptions[];

function withAuthenticatorResult(credential: PublicKeyCredential | null): void {
    // Node bringt ein eigenes `navigator` ohne `credentials` mit; die Eigenschaft ist
    // im DOM-Typ schreibgeschützt, daher der Weg über `defineProperty`.
    Object.defineProperty(navigator, 'credentials', {
        configurable: true,
        value: {
            create: async (options: CredentialCreationOptions) => {
                createdWith.push(options);

                return credential;
            },
            get: async (options: CredentialRequestOptions) => {
                askedFor.push(options);

                return credential;
            },
        },
    });
}

beforeEach(() => {
    requestedUrls = [];
    posted = [];
    serverAnswer = { redirect: '/user/profile' };
    parsedFrom = [];
    createdWith = [];
    askedFor = [];

    globalThis.axios = {
        get: async (url: string) => {
            requestedUrls.push(url);

            return { data: OPTIONS_JSON };
        },
        post: async (url: string, data: unknown, config: unknown) => {
            posted.push({ url, data, config });

            return { data: serverAnswer };
        },
    } as unknown as typeof globalThis.axios;

    // Beide Parser liefern unterscheidbare Ergebnisse, damit am Authenticator
    // ablesbar bleibt, welcher von ihnen gefragt wurde.
    globalThis.PublicKeyCredential = {
        parseCreationOptionsFromJSON: (json: unknown) => {
            parsedFrom.push(json);

            return PARSED_CREATION_OPTIONS;
        },
        parseRequestOptionsFromJSON: (json: unknown) => {
            parsedFrom.push(json);

            return PARSED_REQUEST_OPTIONS;
        },
    } as unknown as typeof PublicKeyCredential;

    withAuthenticatorResult(CREDENTIAL);
});

describe('registerPasskey', () => {
    it('reicht die Optionen des Servers an den Authenticator', async () => {
        await registerPasskey('Mein Passkey');

        expect(requestedUrls).toEqual(['/user/passkeys/register/options']);
        expect(parsedFrom).toEqual([OPTIONS_JSON]);
        expect(createdWith).toEqual([{ publicKey: PARSED_CREATION_OPTIONS }]);
    });

    it('schickt die Attestation mit dem gewählten Namen an den Server und gibt dessen Antwort zurück', async () => {
        serverAnswer = STORED_PASSKEY;

        const stored = await registerPasskey('Mein Passkey');

        expect(posted).toEqual([
            {
                url: '/user/passkeys/register',
                data: { ...CREDENTIAL_JSON, name: 'Mein Passkey' },
                config: JSON_HEADERS,
            },
        ]);
        expect(stored).toBe(STORED_PASSKEY);
    });

    it('bricht ab, wenn der Authenticator kein Credential liefert', async () => {
        withAuthenticatorResult(null);

        await expect(registerPasskey('Mein Passkey')).rejects.toThrow('No credential returned by the authenticator.');
        expect(posted).toEqual([]);
    });
});

describe('authenticateWithPasskey', () => {
    it('reicht die Optionen des Servers an den Authenticator', async () => {
        await authenticateWithPasskey();

        expect(requestedUrls).toEqual(['/passkeys/authenticate/options']);
        expect(parsedFrom).toEqual([OPTIONS_JSON]);
        expect(askedFor).toEqual([{ publicKey: PARSED_REQUEST_OPTIONS }]);
    });

    it('schickt die Assertion als JSON an den Server und gibt dessen Ziel zurück', async () => {
        const redirect = await authenticateWithPasskey();

        expect(posted).toEqual([{ url: '/passkeys/authenticate', data: CREDENTIAL_JSON, config: JSON_HEADERS }]);
        expect(redirect).toBe('/user/profile');
    });

    it('bricht ab, wenn der Authenticator kein Credential liefert', async () => {
        withAuthenticatorResult(null);

        await expect(authenticateWithPasskey()).rejects.toThrow('No credential returned by the authenticator.');
        expect(posted).toEqual([]);
    });
});

describe('confirmWithPasskey', () => {
    it('reicht die Optionen des Servers an den Authenticator', async () => {
        await confirmWithPasskey();

        expect(requestedUrls).toEqual(['/user/passkeys/confirm/options']);
        expect(parsedFrom).toEqual([OPTIONS_JSON]);
        expect(askedFor).toEqual([{ publicKey: PARSED_REQUEST_OPTIONS }]);
    });

    it('schickt die Assertion als JSON an den Server und gibt dessen Ziel zurück', async () => {
        const redirect = await confirmWithPasskey();

        expect(posted).toEqual([{ url: '/user/passkeys/confirm', data: CREDENTIAL_JSON, config: JSON_HEADERS }]);
        expect(redirect).toBe('/user/profile');
    });

    it('fordert das gemerkte Ziel an, wenn die Vollseiten-Variante fragt', async () => {
        await confirmWithPasskey(true);

        expect(posted.map((request) => request.url)).toEqual(['/user/passkeys/confirm?intended=1']);
    });

    it('bricht ab, wenn der Authenticator kein Credential liefert', async () => {
        withAuthenticatorResult(null);

        await expect(confirmWithPasskey()).rejects.toThrow('No credential returned by the authenticator.');
        expect(posted).toEqual([]);
    });
});

describe('globalThis.Passkeys', () => {
    it('hält die Helfer für die Alpine-Handler im Blade-Template bereit', () => {
        expect(globalThis.Passkeys).toEqual({ registerPasskey, authenticateWithPasskey, confirmWithPasskey });
    });
});
