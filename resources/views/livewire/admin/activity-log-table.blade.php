@php($th = 'px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600 dark:text-gray-300')
<div>
    @if ($activities->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.activity_log_empty') }}
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-auto">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th scope="col" class="{{ $th }}">
                            {{ __('app.activity_log_when') }}
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
                                {{ $activity->created_at?->timezone('Europe/Berlin')->isoFormat('L LTS') }}
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
                                        class="inline-flex items-center justify-center rounded p-1 text-indigo-600 hover:bg-indigo-50 hover:text-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-indigo-400 dark:hover:bg-gray-700 dark:hover:text-indigo-200"
                                        title="{{ __('app.activity_log_properties_show') }}"
                                        aria-label="{{ __('app.activity_log_properties_show') }}"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a1 1 0 0 0 0 2v3a1 1 0 0 0 1 1h1a1 1 0 1 0 0-2h-1V9Z" clip-rule="evenodd" />
                                        </svg>
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
                <pre class="overflow-x-auto rounded bg-gray-100 dark:bg-gray-900 p-3 text-xs font-mono text-gray-800 dark:text-gray-200">{{ $selectedProperties }}</pre>
            @endif
        </x-slot>

        <x-slot name="footer">
            <button
                type="button"
                wire:click="closeProperties"
                class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {{ __('app.activity_log_properties_modal_close') }}
            </button>
        </x-slot>
    </x-dialog-modal>
</div>
