<x-action-section>
    <x-slot name="title">
        {{ __('app.delete_account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.delete_account_description') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.delete_account_info') }}
        </div>

        {{-- Der Nachweis kommt zuerst, die Warnung zuletzt: Sie ist die letzte
             Hürde vor einer Löschung, die sich nicht zurücknehmen lässt, und
             ginge neben einem Eingabefeld unter. --}}
        <div class="mt-5">
            <x-confirms-password wire:then="confirmUserDeletion">
                <x-danger-button type="button" wire:loading.attr="disabled">
                    {{ __('app.delete_account') }}
                </x-danger-button>
            </x-confirms-password>
        </div>

        <!-- Delete User Confirmation Modal -->
        <x-dialog-modal wire:model.live="confirmingUserDeletion">
            <x-slot name="title">
                {{ __('app.delete_account') }}
            </x-slot>

            <x-slot name="content">
                {{ __('app.delete_account_confirm') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('app.cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3" wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('app.delete_account') }}
                </x-danger-button>
            </x-slot>
        </x-dialog-modal>

        <x-password-confirmation-modal scope="delete-user" />
    </x-slot>
</x-action-section>
