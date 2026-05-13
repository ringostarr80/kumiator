<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-700">
            {{ __('app.email_change_confirmed_message') }}
        </div>

        <div class="mt-4 flex items-center justify-end">
            <a href="{{ route('login') }}" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{ __('app.log_in') }}
            </a>
        </div>
    </x-authentication-card>
</x-guest-layout>
