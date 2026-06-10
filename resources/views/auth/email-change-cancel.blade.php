<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-700">
            {{ __('app.email_change_cancel_prompt') }}
        </div>

        <form method="POST" action="{{ route('email.change.cancel.perform', ['token' => $token]) }}">
            @csrf

            <div class="flex justify-end">
                <x-button>
                    {{ __('app.email_change_cancel_button') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
