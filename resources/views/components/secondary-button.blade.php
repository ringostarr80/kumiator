<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-xs hover:bg-gray-50 dark:hover:bg-gray-700 active:bg-gray-50 dark:active:bg-gray-700 focus-ring disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
