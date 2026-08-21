@php
    $confirmableId = md5($attributes->wire('then'));
@endphp

<span
    {{ $attributes->wire('then') }}
    {{-- Livewire wertet die Rückfrage nur am Element aus, das die Aktion auslöst.
         Der Klick öffnet über Alpine nur den Passwortdialog, also erscheint sie bei
         abgelaufener Bestätigung erst nach dem Eintippen des Passworts. --}}
    {{ $attributes->wire('confirm') }}
    x-data
    x-ref="span"
    x-on:click="$wire.startConfirmingPassword('{{ $confirmableId }}')"
    x-on:password-confirmed.window="setTimeout(() => $event.detail.id === '{{ $confirmableId }}' && $refs.span.dispatchEvent(new CustomEvent('then', { bubbles: false })), 250);"
>
    {{ $slot }}
</span>
