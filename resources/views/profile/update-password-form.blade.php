@php
    $passwordLoginDisabled = $this->user->isPasswordLoginDisabled();
@endphp

<x-form-section submit="updatePassword">
    <x-slot name="title">
        {{ __('app.update_password') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.update_password_description') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 sm:col-span-4">
            {{-- Ohne nutzbares Passwort tritt der Passkey an seine Stelle: Das Feld
                 verschwindet, der Nachweis nicht. --}}
            @if ($passwordLoginDisabled)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('app.password_change_passkey_hint') }}
                </p>
            @else
                <x-label for="current_password" value="{{ __('app.current_password') }}" />
                <x-input id="current_password" type="password" class="mt-1 block w-full" wire:model="state.current_password" autocomplete="current-password" />
            @endif

            <x-input-error for="current_password" class="mt-2" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-label for="password" value="{{ __('app.new_password') }}" />
            <x-input id="password" type="password" class="mt-1 block w-full" wire:model="state.password" autocomplete="new-password" />
            <x-input-error for="password" class="mt-2" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-label for="password_confirmation" value="{{ __('app.confirm_password') }}" />
            <x-input id="password_confirmation" type="password" class="mt-1 block w-full" wire:model="state.password_confirmation" autocomplete="new-password" />
            <x-input-error for="password_confirmation" class="mt-2" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('app.saved') }}
        </x-action-message>

        @if ($passwordLoginDisabled)
            {{-- Ein Submit-Knopf, der nie absendet: Enter in einem Feld klickt den
                 ersten Submit-Knopf des Formulars, und ohne einen solchen sendet
                 der Browser bei zwei Passwortfeldern gar nichts ab — Enter
                 verhallte. Der abgefangene Klick öffnet nur den Dialog; gespeichert
                 wird nach dem Nachweis über `wire:then`. --}}
            <x-confirms-password wire:then="updatePassword">
                <x-button x-on:click.prevent="">
                    {{ __('app.save') }}
                </x-button>
            </x-confirms-password>

            <x-password-confirmation-modal scope="update-password" />
        @else
            <x-button>
                {{ __('app.save') }}
            </x-button>
        @endif
    </x-slot>
</x-form-section>
