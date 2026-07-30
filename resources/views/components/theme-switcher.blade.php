<div class="relative" x-data="themeSwitcher()" x-init="init()">
    <button @click="open = !open"
            class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300 transition">
        <template x-if="theme === 'light'">
            <x-heroicon-o-sun class="h-5 w-5" />
        </template>
        <template x-if="theme === 'dark'">
            <x-heroicon-o-moon class="h-5 w-5" />
        </template>
        <template x-if="theme === 'system'">
            <x-heroicon-o-computer-desktop class="h-5 w-5" />
        </template>
    </button>

    <div x-show="open" @click.outside="open = false" x-transition
         class="absolute right-0 mt-2 w-36 bg-white dark:bg-gray-800 rounded-md shadow-lg border border-gray-100 dark:border-gray-700 z-50">
        <button @click="setTheme('system')"
                class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300"
                :class="{ 'font-semibold': theme === 'system' }">
            <x-heroicon-o-computer-desktop class="h-4 w-4" />
            System
        </button>
        <button @click="setTheme('light')"
                class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300"
                :class="{ 'font-semibold': theme === 'light' }">
            <x-heroicon-o-sun class="h-4 w-4" />
            Light
        </button>
        <button @click="setTheme('dark')"
                class="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:focus-visible:outline-indigo-300"
                :class="{ 'font-semibold': theme === 'dark' }">
            <x-heroicon-o-moon class="h-4 w-4" />
            Dark
        </button>
    </div>
</div>

<script>
    function themeSwitcher() {
        return {
            open: false,
            theme: localStorage.getItem('theme') || 'system',
            init() {
                this.applyTheme();
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                    if (this.theme === 'system') {
                        this.applyTheme();
                    }
                });
            },
            setTheme(value) {
                this.theme = value;
                localStorage.setItem('theme', value);
                this.applyTheme();
                this.open = false;
            },
            applyTheme() {
                const isDark = this.theme === 'dark' || (this.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', isDark);
            }
        }
    }
</script>
