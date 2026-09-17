@props([
    'scope',
    'title' => null,
    'content' => null,
    'button' => __('app.confirm'),
])

@php
    $user = auth()->user();
    // Beide Fragen stellt das Modal an den Nutzer, nicht an die einbettende
    // Komponente: Jetstreams 2FA-Formular bindet es genauso ein wie unsere
    // Passkey-Verwaltung und wüsste von einem Prop nichts.
    $passwordLoginDisabled = $user?->isPasswordLoginDisabled() ?? false;
    $hasPasskey = $user?->hasPasskey() ?? false;
    // Ohne beides bleibt im Dialog nur der Abbrechen-Knopf; der Text nennt dann
    // den Weg über die Administration, statt einen Nachweis zu verlangen.
    $content ??= match (true) {
        $passwordLoginDisabled && !$hasPasskey => __('app.password_confirmation_unavailable'),
        $hasPasskey => __('app.confirm_password_or_passkey_security'),
        default => __('app.confirm_password_security'),
    };
    // Der Titel nennt das Passwort nur, wo es der einzige Weg ist.
    $title ??= $passwordLoginDisabled || $hasPasskey
        ? __('app.confirm_identity_title')
        : __('app.confirm_password_title');
@endphp

{{-- Der Dialog gehört an eine feste Stelle der Komponente: Gäbe ihn stattdessen
     der erste `x-confirms-password` aus, wanderte er mit den Bedingungen um die
     Buttons herum und läge zeitweise in einem ausgeblendeten Teilbaum. --}}
<x-dialog-modal :id="'confirm-password-' . $scope" wire:model.live="confirmingPassword">
    <x-slot name="title">
        {{ $title }}
    </x-slot>

    <x-slot name="content">
        {{ $content }}

        @unless ($passwordLoginDisabled)
            <div class="mt-4" x-data="{}" x-on:confirming-password.window="setTimeout(() => $refs.confirmable_password.focus(), 250)">
                <x-input type="password" class="mt-1 block w-3/4" placeholder="{{ __('app.password') }}" autocomplete="current-password"
                            x-ref="confirmable_password"
                            wire:model="confirmablePassword"
                            wire:keydown.enter="confirmPassword" />

                <x-input-error for="confirmable_password" class="mt-2" />
            </div>
        @endunless

        @if ($hasPasskey)
            {{-- Der Dialog bleibt ausgeblendet im DOM, sein Alpine-Zustand überlebt das
                 Schließen also. Ohne das Leeren stünde die Meldung eines abgebrochenen
                 Versuchs schon da, bevor die nächste Bestätigung beginnt. --}}
            <div class="mt-4" x-data="passkeyConfirmation('{{ __('app.passkey_confirmation_failed') }}')"
                 x-on:confirming-password.window="errorMessage = ''">
                <x-secondary-button type="button" x-on:click="confirm()" x-bind:disabled="loading">
                    {{ __('app.confirm_with_passkey') }}
                </x-secondary-button>

                <p class="mt-2 text-sm text-red-600 dark:text-red-400" x-show="errorMessage" x-text="errorMessage" x-cloak></p>
            </div>
        @endif
    </x-slot>

    <x-slot name="footer">
        <x-secondary-button wire:click="stopConfirmingPassword" wire:loading.attr="disabled">
            {{ __('app.cancel') }}
        </x-secondary-button>

        @unless ($passwordLoginDisabled)
            <x-button class="ms-3" dusk="confirm-password-button" wire:click="confirmPassword" wire:loading.attr="disabled">
                {{ $button }}
            </x-button>
        @endunless
    </x-slot>
</x-dialog-modal>
