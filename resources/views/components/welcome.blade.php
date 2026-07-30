<div class="p-6 lg:p-8 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
    <x-application-logo class="block h-12 w-auto" />

    <h1 class="mt-8 text-2xl font-medium text-gray-900 dark:text-gray-100">
        {{ __('app.welcome_jetstream') }}
    </h1>

    <p class="mt-6 text-gray-500 dark:text-gray-400 leading-relaxed">
        {{ __('app.welcome_jetstream_description') }}
    </p>
</div>

<div class="bg-gray-200/25 dark:bg-gray-800/25 grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 p-6 lg:p-8">
    <div>
        <div class="flex items-center">
            <x-heroicon-o-book-open class="size-6 stroke-gray-400" />
            <h2 class="ms-3 text-xl font-semibold text-gray-900 dark:text-gray-100">
                <a href="https://laravel.com/docs" class="focus-ring">{{ __('app.documentation') }}</a>
            </h2>
        </div>

        <p class="mt-4 text-gray-500 dark:text-gray-400 text-sm leading-relaxed">
            {{ __('app.documentation_description') }}
        </p>

        <p class="mt-4 text-sm">
            <a href="https://laravel.com/docs" class="inline-flex items-center font-semibold text-indigo-700 dark:text-indigo-400 focus-ring">
                {{ __('app.explore_documentation') }}

                <x-heroicon-m-arrow-long-right class="ms-1 size-5 fill-indigo-500 dark:fill-indigo-400" />
            </a>
        </p>
    </div>

    <div>
        <div class="flex items-center">
            <x-heroicon-o-video-camera class="size-6 stroke-gray-400" />
            <h2 class="ms-3 text-xl font-semibold text-gray-900 dark:text-gray-100">
                <a href="https://laracasts.com" class="focus-ring">{{ __('app.laracasts') }}</a>
            </h2>
        </div>

        <p class="mt-4 text-gray-500 dark:text-gray-400 text-sm leading-relaxed">
            {{ __('app.laracasts_description') }}
        </p>

        <p class="mt-4 text-sm">
            <a href="https://laracasts.com" class="inline-flex items-center font-semibold text-indigo-700 dark:text-indigo-400 focus-ring">
                {{ __('app.start_watching_laracasts') }}

                <x-heroicon-m-arrow-long-right class="ms-1 size-5 fill-indigo-500 dark:fill-indigo-400" />
            </a>
        </p>
    </div>

    <div>
        <div class="flex items-center">
            <x-heroicon-o-photo class="size-6 stroke-gray-400" />
            <h2 class="ms-3 text-xl font-semibold text-gray-900 dark:text-gray-100">
                <a href="https://tailwindcss.com/" class="focus-ring">{{ __('app.tailwind') }}</a>
            </h2>
        </div>

        <p class="mt-4 text-gray-500 dark:text-gray-400 text-sm leading-relaxed">
            {{ __('app.tailwind_description') }}
        </p>
    </div>

    <div>
        <div class="flex items-center">
            <x-heroicon-o-lock-closed class="size-6 stroke-gray-400" />
            <h2 class="ms-3 text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ __('app.authentication') }}
            </h2>
        </div>

        <p class="mt-4 text-gray-500 dark:text-gray-400 text-sm leading-relaxed">
            {{ __('app.authentication_description') }}
        </p>
    </div>
</div>
