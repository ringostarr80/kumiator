@php
    $user = auth()->user();
    $passwordLoginDisabled = $user?->isPasswordLoginDisabled() ?? false;
    $hasPasskey = $user?->hasPasskey() ?? false;
@endphp

<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        {{-- Der Text muss die Bedienelemente treffen, die darunter übrig bleiben:
             Ohne nutzbares Passwort gibt die Seite kein Feld mehr aus, und fällt
             auch der Passkey-Knopf weg, bliebe eine Karte ohne jedes
             Bedienelement zurück. --}}
        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            @if ($passwordLoginDisabled && !$hasPasskey)
                {{ __('app.password_confirmation_unavailable') }}
            @elseif ($hasPasskey)
                {{ __('app.confirm_password_or_passkey_security') }}
            @else
                {{ __('app.secure_area') }}
            @endif
        </div>

        <x-validation-errors class="mb-4" />

        @unless ($passwordLoginDisabled)
            <form method="POST" action="{{ route('password.confirm') }}">
                @csrf

                <div>
                    <x-label for="password" value="{{ __('app.password') }}" />
                    <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
                </div>

                <div class="flex justify-end mt-4">
                    <x-button class="ms-4">
                        {{ __('app.confirm') }}
                    </x-button>
                </div>
            </form>
        @endunless

        @if ($hasPasskey)
            <div @class(['mt-4' => !$passwordLoginDisabled]) x-data="passkeyConfirmationPage('{{ __('app.passkey_confirmation_failed') }}')">
                <x-secondary-button type="button" class="w-full justify-center" x-on:click="confirm()" x-bind:disabled="loading">
                    {{ __('app.confirm_with_passkey') }}
                </x-secondary-button>

                <p class="mt-2 text-sm text-red-600 dark:text-red-400" x-show="errorMessage" x-text="errorMessage" x-cloak></p>
            </div>
        @endif
    </x-authentication-card>
</x-guest-layout>
