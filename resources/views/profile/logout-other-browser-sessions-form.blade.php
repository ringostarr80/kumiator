<x-action-section>
    <x-slot name="title">
        {{ __('app.browser_sessions') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.browser_sessions_description') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.browser_sessions_info') }}
        </div>

        @if (count($this->sessions) > 0)
            <div class="mt-5 space-y-6">
                <!-- Other Browser Sessions -->
                @foreach ($this->sessions as $session)
                    <div class="flex items-center">
                        <div>
                            @if ($session->agent->isDesktop())
                                <x-heroicon-o-computer-desktop class="size-8 text-gray-500 dark:text-gray-400" />
                            @else
                                <x-heroicon-o-device-phone-mobile class="size-8 text-gray-500 dark:text-gray-400" />
                            @endif
                        </div>

                        <div class="ms-3">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                {{ $session->agent->platform() ? $session->agent->platform() : __('app.unknown') }} - {{ $session->agent->browser() ? $session->agent->browser() : __('app.unknown') }}
                            </div>

                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $session->ip_address }},

                                    @if ($session->is_current_device)
                                        <span class="text-green-500 font-semibold">{{ __('app.this_device') }}</span>
                                    @else
                                        {{ __('app.last_active') }} {{ $session->last_active }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Der Nachweis kommt zuerst, die Rückfrage danach: Bis dahin steht fest,
             wer da klickt, und der Dialog muss kein Passwortfeld mehr tragen. --}}
        <div class="flex items-center mt-5">
            <x-confirms-password wire:then="confirmLogout">
                <x-button type="button" wire:loading.attr="disabled">
                    {{ __('app.logout_other_sessions') }}
                </x-button>
            </x-confirms-password>

            <x-action-message class="ms-3" on="loggedOut">
                {{ __('app.done') }}
            </x-action-message>
        </div>

        <!-- Log Out Other Devices Confirmation Modal -->
        <x-dialog-modal wire:model.live="confirmingLogout">
            <x-slot name="title">
                {{ __('app.logout_other_sessions') }}
            </x-slot>

            <x-slot name="content">
                {{ __('app.logout_other_sessions_confirm') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingLogout')" wire:loading.attr="disabled">
                    {{ __('app.cancel') }}
                </x-secondary-button>

                <x-button class="ms-3"
                            wire:click="logoutOtherBrowserSessions"
                            wire:loading.attr="disabled">
                    {{ __('app.logout_other_sessions') }}
                </x-button>
            </x-slot>
        </x-dialog-modal>

        <x-password-confirmation-modal scope="browser-sessions" />
    </x-slot>
</x-action-section>
