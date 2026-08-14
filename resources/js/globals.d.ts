import type { AxiosStatic } from 'axios';

/**
 * Blade templates reach these helpers through the global scope instead of ES module imports,
 * so the entry points have no import that could carry their types.
 */
declare global {
    var axios: AxiosStatic;

    var Passkeys: {
        registerPasskey(credentialName: string): Promise<object>;
        authenticateWithPasskey(email?: string | null): Promise<string>;
    };

    // Alpine factories stay untyped here: a function type would become the contextual type of the
    // returned literal, and `this` inside the component methods would resolve against it instead of
    // against the literal's own shape. The parameters get their types from the JSDoc at each factory.
    var passkeyLogin: unknown;
    var passkeyRegistration: unknown;

    /** Provided by the Livewire runtime, which ships its own script tag */
    var Livewire: {
        dispatch(event: string, params?: Record<string, unknown>): void;
    };
}

export {};
