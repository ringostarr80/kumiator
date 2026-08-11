<div class="relative" x-data="themeSwitcher()" x-init="init()">
    <button @click="open = !open"
            class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white focus-ring transition">
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
        <x-switcher-option @click="setTheme('system')" class="w-full"
                           ::class="{ 'font-semibold': theme === 'system' }">
            <x-heroicon-o-computer-desktop class="h-4 w-4" />
            System
        </x-switcher-option>
        <x-switcher-option @click="setTheme('light')" class="w-full"
                           ::class="{ 'font-semibold': theme === 'light' }">
            <x-heroicon-o-sun class="h-4 w-4" />
            Light
        </x-switcher-option>
        <x-switcher-option @click="setTheme('dark')" class="w-full"
                           ::class="{ 'font-semibold': theme === 'dark' }">
            <x-heroicon-o-moon class="h-4 w-4" />
            Dark
        </x-switcher-option>
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
