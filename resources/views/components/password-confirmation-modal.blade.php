@props([
    'scope',
    'title' => __('app.confirm_password_title'),
    'content' => __('app.confirm_password_security'),
    'button' => __('app.confirm'),
])

{{-- Der Dialog gehört an eine feste Stelle der Komponente: Gäbe ihn stattdessen
     der erste `x-confirms-password` aus, wanderte er mit den Bedingungen um die
     Buttons herum und läge zeitweise in einem ausgeblendeten Teilbaum. --}}
<x-dialog-modal :id="'confirm-password-' . $scope" wire:model.live="confirmingPassword">
    <x-slot name="title">
        {{ $title }}
    </x-slot>

    <x-slot name="content">
        {{ $content }}

        <div class="mt-4" x-data="{}" x-on:confirming-password.window="setTimeout(() => $refs.confirmable_password.focus(), 250)">
            <x-input type="password" class="mt-1 block w-3/4" placeholder="{{ __('app.password') }}" autocomplete="current-password"
                        x-ref="confirmable_password"
                        wire:model="confirmablePassword"
                        wire:keydown.enter="confirmPassword" />

            <x-input-error for="confirmable_password" class="mt-2" />
        </div>
    </x-slot>

    <x-slot name="footer">
        <x-secondary-button wire:click="stopConfirmingPassword" wire:loading.attr="disabled">
            {{ __('app.cancel') }}
        </x-secondary-button>

        <x-button class="ms-3" dusk="confirm-password-button" wire:click="confirmPassword" wire:loading.attr="disabled">
            {{ $button }}
        </x-button>
    </x-slot>
</x-dialog-modal>
