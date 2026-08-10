{{-- Eigene Rezeptur neben dropdown-link, weil die Umschalter-Panels im Dunkelmodus tiefer liegen
     als das Dropdown-Panel und ihr Hover-Ton deshalb ein anderer ist. --}}
@props(['href' => null])

@php
    $classes = 'flex items-center gap-2 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus-ring-inset active:bg-gray-50 dark:active:bg-gray-700';
@endphp

@if ($href === null)
    <button {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@else
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@endif
