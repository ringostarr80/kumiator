<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'AssociationManager') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100">

        <!-- Navigation -->
        <nav class="bg-white border-b border-gray-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16 items-center">
                    <div class="font-semibold text-gray-800 text-lg">
                        {{ config('app.name', 'AssociationManager') }}
                    </div>

                    @if (Route::has('login'))
                        <div class="flex items-center gap-4">
                            @auth
                                <a href="{{ url('/dashboard') }}"
                                   class="text-sm text-gray-700 hover:text-gray-900 underline">
                                    Dashboard
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                   class="text-sm text-gray-700 hover:text-gray-900 underline">
                                    Anmelden
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}"
                                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 transition ease-in-out duration-150">
                                        Registrieren
                                    </a>
                                @endif
                            @endauth
                        </div>
                    @endif
                </div>
            </div>
        </nav>

        <!-- Hero -->
        <main class="min-h-screen flex items-center justify-center">
            <div class="max-w-2xl mx-auto px-6 text-center">
                <div class="bg-white rounded-2xl shadow-xl p-10">
                    <h1 class="text-3xl font-semibold text-gray-800 mb-4">
                        Willkommen beim AssociationManager
                    </h1>
                    <p class="text-gray-500 leading-relaxed">
                        Diese Web-Applikation dient der zentralen Verwaltung deines Vereins.
                        Mitglieder, Beiträge und weitere vereinsinterne Daten lassen sich hier
                        übersichtlich erfassen und pflegen.
                    </p>

                    @if (Route::has('login'))
                        <div class="mt-8 flex justify-center gap-4">
                            @auth
                                <a href="{{ url('/dashboard') }}"
                                   class="inline-flex items-center px-6 py-3 bg-gray-800 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-gray-700 transition ease-in-out duration-150">
                                    Zum Dashboard
                                </a>
                            @else
                                <a href="{{ route('login') }}"
                                   class="inline-flex items-center px-6 py-3 bg-white border border-gray-300 rounded-md font-semibold text-sm text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition ease-in-out duration-150">
                                    Anmelden
                                </a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}"
                                       class="inline-flex items-center px-6 py-3 bg-gray-800 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-gray-700 transition ease-in-out duration-150">
                                        Registrieren
                                    </a>
                                @endif
                            @endauth
                        </div>
                    @endif
                </div>
            </div>
        </main>

    </body>
</html>
