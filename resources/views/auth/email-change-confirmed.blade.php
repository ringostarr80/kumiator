<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.email_change_confirmed_message') }}
        </div>

        <div class="mt-4 flex items-center justify-end">
            <a href="{{ route('login') }}" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md focus-ring">
                {{ __('app.log_in') }}
            </a>
        </div>
    </x-authentication-card>
</x-guest-layout>
