<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.registration_pending_title') }}
        </div>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.registration_pending_message') }}
        </div>

        <div class="mt-4 flex items-center justify-end">
            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <x-button type="submit">
                    {{ __('app.log_out') }}
                </x-button>
            </form>
        </div>
    </x-authentication-card>
</x-guest-layout>
