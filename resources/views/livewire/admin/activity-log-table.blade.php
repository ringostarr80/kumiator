@php($th = 'px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600 dark:text-gray-300')
@php($selectClasses = 'block mt-1 w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 focus:border-indigo-600 dark:focus:border-indigo-300 focus:ring-indigo-600 dark:focus:ring-indigo-300 rounded-md shadow-xs')
<div>
    {{-- Filter ein-/ausklappbar (Alpine, rein clientseitig). Startet aufgeklappt,
         wenn bereits gefiltert wird — z. B. via #[Url] aus einem geteilten oder
         gebookmarkten Link; sonst wäre die Liste ohne sichtbaren Grund gefiltert.
         Der Punkt am Button signalisiert aktive Filter im eingeklappten Zustand. --}}
    <div x-data="{ open: @js($hasActiveFilters) }" class="mb-4">
        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            class="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-gray-600 hover:text-gray-900 focus-ring dark:text-gray-300 dark:hover:text-white"
        >
            <x-heroicon-m-funnel class="h-4 w-4" aria-hidden="true" />
            {{ __('app.activity_log_filter_toggle') }}
            @if ($hasActiveFilters)
                <span class="ml-1 inline-block h-2 w-2 rounded-full bg-indigo-500" aria-hidden="true"></span>
            @endif
            <x-heroicon-m-chevron-down class="h-4 w-4" x-show="! open" aria-hidden="true" />
            <x-heroicon-m-chevron-up class="h-4 w-4" x-cloak x-show="open" aria-hidden="true" />
        </button>

        {{-- Spaltenfilter: UND-verknüpft; jede Änderung springt via updated()-Hook
             zurück auf Seite 1. Der Zustand ist per #[Url] im Query-String.
             2-Spalten-Grid: die DOM-Reihenfolge bildet die logischen Paare
             (von/bis, kategorie/ereignis, causer/subject). --}}
        <div x-cloak x-show="open" class="mt-3">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-label for="filter-date-from" value="{{ __('app.activity_log_filter_date_from') }}" />
                    <x-input id="filter-date-from" class="block mt-1 w-full" type="date" wire:model.live="filters.dateFrom" />
                    <x-input-error for="filters.dateFrom" class="mt-2" />
                </div>

                <div>
                    <x-label for="filter-date-to" value="{{ __('app.activity_log_filter_date_to') }}" />
                    <x-input id="filter-date-to" class="block mt-1 w-full" type="date" wire:model.live="filters.dateTo" />
                    <x-input-error for="filters.dateTo" class="mt-2" />
                </div>

                <div>
                    <x-label for="filter-channel" value="{{ __('app.activity_log_log_name') }}" />
                    <select id="filter-channel" wire:model.live="filters.channel" class="{{ $selectClasses }}">
                        <option value="">{{ __('app.activity_log_filter_all') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}">{{ $channel->value }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-label for="filter-event" value="{{ __('app.activity_log_event') }}" />
                    <select id="filter-event" wire:model.live="filters.event" class="{{ $selectClasses }}">
                        <option value="">{{ __('app.activity_log_filter_all') }}</option>
                        @foreach ($events as $event)
                            <option value="{{ $event->value }}">{{ $event->description() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-label for="filter-causer" value="{{ __('app.activity_log_causer') }}" />
                    <x-input id="filter-causer" class="block mt-1 w-full" type="text" wire:model.live.debounce.400ms="filters.causer" />
                </div>

                <div>
                    <x-label for="filter-subject" value="{{ __('app.activity_log_subject') }}" />
                    <x-input id="filter-subject" class="block mt-1 w-full" type="text" wire:model.live.debounce.400ms="filters.subject" />
                </div>
            </div>

            <div class="mt-3">
                <x-secondary-button type="button" wire:click="resetFilters">
                    {{ __('app.activity_log_filter_reset') }}
                </x-secondary-button>
            </div>
        </div>
    </div>

    @if ($activities->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ $hasActiveFilters ? __('app.activity_log_no_matches') : __('app.activity_log_empty') }}
        </p>
    @else
        <div class="mb-4">
            {{ $activities->links() }}
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-auto">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th
                            scope="col"
                            class="{{ $th }}"
                            aria-sort="{{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}"
                        >
                            <button
                                type="button"
                                wire:click="sortByCreatedAt"
                                class="inline-flex items-center gap-1 rounded-sm hover:text-gray-900 dark:hover:text-white focus-ring"
                            >
                                {{ __('app.activity_log_when') }}
                                @if ($sortDirection === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3 w-3" aria-hidden="true" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3 w-3" aria-hidden="true" />
                                @endif
                            </button>
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_log_name') }}
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_event') }}
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_causer') }}
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_subject') }}
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_description') }}
                        </th>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_properties') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-800">
                    @foreach ($activities as $activity)
                        <tr wire:key="activity-{{ $activity->id }}">
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                {{ $activity->created_at?->timezone(\App\Enums\AppTimezone::DISPLAY->value)->isoFormat('L LTS') }}
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                {{ $activity->log_name ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                {{ $activity->event ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                @if ($activity->causer)
                                    {{ $activity->causer->name }}
                                @elseif ($activity->causer_type && $activity->causer_id)
                                    {{ __('app.activity_log_deleted_record', ['type' => __('app.morph_' . $activity->causer_type)]) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                @if ($activity->subject)
                                    {{ $activity->subject->name }}
                                @elseif ($activity->subject_type && $activity->subject_id)
                                    {{ __('app.activity_log_deleted_record', ['type' => __('app.morph_' . $activity->subject_type)]) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200">
                                {{ $activity->description }}
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                @if ($activity->properties->isNotEmpty())
                                    <button
                                        type="button"
                                        wire:click="showProperties({{ $activity->id }})"
                                        class="inline-flex items-center justify-center rounded-sm p-1 text-indigo-600 hover:bg-indigo-50 hover:text-indigo-800 active:bg-indigo-50 focus-ring dark:text-indigo-400 dark:hover:bg-gray-700 dark:hover:text-indigo-200 dark:active:bg-gray-700"
                                        title="{{ __('app.activity_log_properties_show') }}"
                                        aria-label="{{ __('app.activity_log_properties_show') }}"
                                    >
                                        <x-heroicon-m-information-circle class="h-4 w-4" aria-hidden="true" />
                                    </button>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $activities->links() }}
        </div>
    @endif

    <x-dialog-modal wire:model.live="showPropertiesModal">
        <x-slot name="title">
            {{ __('app.activity_log_properties_modal_title') }}
        </x-slot>

        <x-slot name="content">
            @if ($selectedProperties !== null)
                <pre class="overflow-x-auto rounded-sm bg-gray-100 dark:bg-gray-900 p-3 text-xs font-mono text-gray-800 dark:text-gray-200">{{ $selectedProperties }}</pre>
            @endif
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                wire:click="closeProperties"
                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50 active:bg-gray-50 focus-ring dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:active:bg-gray-700"
            >
                {{ __('app.activity_log_properties_modal_close') }}
            </button>
        </x-slot>
    </x-dialog-modal>
</div>
