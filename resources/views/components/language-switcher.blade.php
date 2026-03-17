<div class="relative" x-data="{ open: false }">
    <button @click="open = !open"
            class="flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900 transition">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/>
        </svg>
        {{ strtoupper(app()->getLocale()) }}
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div x-show="open" @click.outside="open = false" x-transition
         class="absolute right-0 mt-2 w-32 bg-white rounded-md shadow-lg border border-gray-100 z-50">
        <a href="{{ route('locale.switch', 'de') }}"
           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 {{ app()->getLocale() === 'de' ? 'font-semibold' : '' }}">
            🇩🇪 Deutsch
        </a>
        <a href="{{ route('locale.switch', 'en') }}"
           class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 {{ app()->getLocale() === 'en' ? 'font-semibold' : '' }}">
            🇬🇧 English
        </a>
    </div>
</div>
