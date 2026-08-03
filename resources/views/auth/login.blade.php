<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />

        @session('status')
            <div class="mb-4 font-medium text-sm text-green-600">
                {{ $value }}
            </div>
        @endsession

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <x-label for="email" value="{{ __('app.email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div class="mt-4">
                <x-label for="password" value="{{ __('app.password') }}" />
                <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="flex items-center">
                    <x-checkbox id="remember_me" name="remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('app.remember_me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                @if (Route::has('password.request'))
                    <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md focus-ring" href="{{ route('password.request') }}">
                        {{ __('app.forgot_password') }}
                    </a>
                @endif

                <x-button class="ms-4">
                    {{ __('app.log_in') }}
                </x-button>
            </div>
        </form>

        {{-- Passkey login --}}
        <div x-data="passkeyLogin('{{ __('app.passkey_auth_error') }}')" class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-6">
            <x-secondary-button
                type="button"
                class="w-full justify-center"
                @click="authenticate"
                x-bind:disabled="loading"
            >
                <span x-show="!loading">{{ __('app.sign_in_with_passkey') }}</span>
                <span x-show="loading">…</span>
            </x-secondary-button>
            <p x-show="errorMessage" x-text="errorMessage" class="mt-2 text-sm text-red-600 text-center"></p>
        </div>
    </x-authentication-card>
</x-guest-layout>
