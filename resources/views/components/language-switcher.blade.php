<div class="relative" x-data="{ open: false }">
    <button @click="open = !open"
            class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white focus-ring transition">
        <x-heroicon-o-language class="h-4 w-4" />
        {{ strtoupper(app()->getLocale()) }}
        <x-heroicon-o-chevron-down class="h-3 w-3" />
    </button>

    <div x-show="open" @click.outside="open = false" x-transition
         class="absolute right-0 mt-2 w-32 bg-white dark:bg-gray-800 rounded-md shadow-lg border border-gray-100 dark:border-gray-700 z-50">
        <a href="{{ route('locale.switch', 'de') }}"
           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-ring-inset active:bg-gray-50 dark:active:bg-gray-700 {{ app()->getLocale() === 'de' ? 'font-semibold' : '' }}">
            Deutsch
        </a>
        <a href="{{ route('locale.switch', 'en') }}"
           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-ring-inset active:bg-gray-50 dark:active:bg-gray-700 {{ app()->getLocale() === 'en' ? 'font-semibold' : '' }}">
            English
        </a>
    </div>
</div>
