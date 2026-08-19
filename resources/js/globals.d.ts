import type { AxiosStatic } from 'axios';

/**
 * Blade-Templates greifen über den globalen Scope auf diese Helfer zu statt über
 * ES-Module-Importe — die Einstiegspunkte haben deshalb keinen Import, der ihre
 * Typen mitbringen könnte.
 */
declare global {
    var axios: AxiosStatic;

    var Passkeys: {
        registerPasskey(credentialName: string): Promise<object>;
        authenticateWithPasskey(): Promise<string>;
    };

    // Die Alpine-Factories bleiben hier ungetypt: Ein Funktionstyp würde zum kontextuellen Typ des
    // zurückgegebenen Literals, und `this` in den Komponenten-Methoden löste gegen ihn auf statt
    // gegen die Form des Literals selbst. Die Parameter bekommen ihre Typen aus dem JSDoc der Factory.
    var passkeyLogin: unknown;
    var passkeyRegistration: unknown;

    /** Kommt von der Livewire-Runtime, die ihr eigenes Script-Tag mitbringt */
    var Livewire: {
        dispatch(event: string, params?: Record<string, unknown>): void;
    };
}

export {};
