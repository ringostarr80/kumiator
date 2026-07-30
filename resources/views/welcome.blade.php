<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('app.app_name') }}</title>

        <!-- Dark Mode (prevent flash) -->
        <script>
            (function() {
                const theme = localStorage.getItem('theme') || 'system';
                const isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (isDark) document.documentElement.classList.add('dark');
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900">

        <!-- Navigation -->
        <nav class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16 items-center">
                    <div class="font-semibold text-gray-800 dark:text-gray-200 text-lg">
                        {{ __('app.app_name') }}
                    </div>

                    <div class="flex items-center gap-4">
                        <x-theme-switcher />
                        <x-language-switcher />

                        @if (Route::has('login'))
                            @auth
                                <a href="{{ url('/dashboard') }}"
                                   class="text-sm text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300">
                                    {{ __('app.dashboard') }}
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                   class="text-sm text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300">
                                    {{ __('app.login') }}
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}"
                                       class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300 transition ease-in-out duration-150">
                                        {{ __('app.register') }}
                                    </a>
                                @endif
                            @endauth
                        @endif
                    </div>
                </div>
            </div>
        </nav>

        <!-- Hero -->
        <main class="min-h-screen flex items-center justify-center">
            <div class="max-w-2xl mx-auto px-6 text-center">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-10">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 mb-4">
                        {{ __('app.welcome_title') }}
                    </h1>
                    <p class="text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('app.welcome_subtitle') }}
                    </p>

                    @if (Route::has('login'))
                        <div class="mt-8 flex justify-center gap-4">
                            @auth
                                <a href="{{ url('/dashboard') }}"
                                   class="inline-flex items-center px-6 py-3 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-sm text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300 transition ease-in-out duration-150">
                                    {{ __('app.to_dashboard') }}
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                   class="inline-flex items-center px-6 py-3 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-sm text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300 transition ease-in-out duration-150">
                                    {{ __('app.login') }}
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}"
                                       class="inline-flex items-center px-6 py-3 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-sm text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300 transition ease-in-out duration-150">
                                        {{ __('app.register') }}
                                    </a>
                                @endif
                            @endauth
                        </div>
                    @endif
                </div>
            </div>
        </main>

        @livewireScripts
    </body>
</html>
