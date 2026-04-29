<x-action-section>
    <x-slot name="title">
        {{ __('app.passkeys') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.passkeys_description') }}
    </x-slot>

    <x-slot name="content">
        @if (session('passkey_deleted'))
            <div class="mb-4 font-medium text-sm text-green-600">
                {{ __('app.passkey_deleted') }}
            </div>
        @endif

        @if (session('passkey_renamed'))
            <div class="mb-4 font-medium text-sm text-green-600">
                {{ __('app.passkey_renamed') }}
            </div>
        @endif

        {{-- List of registered passkeys --}}
        @if ($passkeys->isEmpty())
            <p class="text-sm text-gray-600">{{ __('app.passkeys_empty') }}</p>
        @else
            <div class="space-y-3">
                @foreach ($passkeys as $passkey)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                         wire:key="passkey-{{ $passkey->id }}">
                        @if ($editingPasskeyId === $passkey->id)
                            <div class="flex-1 mr-3">
                                <x-input
                                    type="text"
                                    wire:model="editingPasskeyName"
                                    maxlength="80"
                                    wire:keydown.enter="renamePasskey"
                                    wire:keydown.escape="cancelRenaming"
                                    class="w-full"
                                    autofocus
                                />
                                @error('editingPasskeyName')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="flex items-center gap-2">
                                <x-button type="button" wire:click="renamePasskey">
                                    {{ __('app.save') }}
                                </x-button>
                                <x-secondary-button type="button" wire:click="cancelRenaming">
                                    {{ __('app.cancel') }}
                                </x-secondary-button>
                            </div>
                        @else
                            <div>
                                <p class="font-medium text-sm text-gray-900">{{ $passkey->name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ __('app.passkey_registered_at') }}: {{ $passkey->created_at->format('d.m.Y') }}
                                    &nbsp;·&nbsp;
                                    @if ($passkey->last_used_at)
                                        {{ __('app.passkey_last_used') }}: {{ $passkey->last_used_at->diffForHumans() }}
                                    @else
                                        {{ __('app.passkey_never_used') }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-secondary-button
                                    size="sm"
                                    type="button"
                                    wire:click="startRenaming('{{ $passkey->id }}')"
                                >
                                    {{ __('app.passkey_rename') }}
                                </x-secondary-button>
                                <x-danger-button
                                    size="sm"
                                    wire:click="deletePasskey('{{ $passkey->id }}')"
                                    wire:confirm="{{ __('app.passkey_delete_confirm') }}"
                                >
                                    {{ __('app.delete') }}
                                </x-danger-button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Add passkey button --}}
        <div class="mt-5">
            <div x-data="passkeyRegistration('{{ __('app.passkey_default_name') }}', '{{ __('app.passkey_added') }}', '{{ __('app.whoops') }}')" @keydown.escape.window="showForm = false">
                <template x-if="!showForm">
                    <x-button type="button" @click="showForm = true">
                        {{ __('app.add_passkey') }}
                    </x-button>
                </template>

                <template x-if="showForm">
                    <div class="flex items-center gap-3">
                        <x-input
                            type="text"
                            x-model="credentialName"
                            placeholder="{{ __('app.passkey_name_placeholder') }}"
                            maxlength="80"
                            @keydown.enter="register"
                            autofocus
                        />
                        <x-button type="button" @click="register" x-bind:disabled="loading">
                            <span x-show="!loading">{{ __('app.confirm') }}</span>
                            <span x-show="loading">…</span>
                        </x-button>
                        <x-secondary-button type="button" @click="showForm = false">
                            {{ __('app.cancel') }}
                        </x-secondary-button>
                    </div>
                </template>

                <p x-show="errorMessage" x-text="errorMessage" class="mt-2 text-sm text-red-600"></p>
                <p x-show="successMessage" x-text="successMessage" class="mt-2 text-sm text-green-600"></p>
            </div>
        </div>
    </x-slot>
</x-action-section>
