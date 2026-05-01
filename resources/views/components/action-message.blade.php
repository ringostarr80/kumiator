@props(['on'])

<div x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 4000); })"
    x-show.transition.out.opacity.duration.1000ms="shown"
    x-transition:leave.opacity.duration.1000ms
    style="display: none;"
    role="status"
    aria-live="polite"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-md bg-green-100 px-3 py-1.5 text-sm font-medium text-green-800 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/40 dark:text-green-200 dark:ring-green-500/30']) }}>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4 shrink-0" aria-hidden="true">
        <path fill-rule="evenodd" d="M16.704 5.296a1 1 0 0 1 0 1.414l-7.5 7.5a1 1 0 0 1-1.414 0l-3.5-3.5a1 1 0 1 1 1.414-1.414l2.793 2.793 6.793-6.793a1 1 0 0 1 1.414 0Z" clip-rule="evenodd" />
    </svg>
    <span>{{ $slot->isEmpty() ? __('app.saved') : $slot }}</span>
</div>
