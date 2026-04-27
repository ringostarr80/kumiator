@php($th = 'px-4 py-2 text-left text-xs font-semibold uppercase text-gray-600 dark:text-gray-300')
<div>
    @if ($activities->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('app.activity_log_empty') }}
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
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
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800 bg-white dark:bg-gray-800">
                    @foreach ($activities as $activity)
                        <tr wire:key="activity-{{ $activity->id }}">
                            <td class="px-4 py-2 text-xs text-gray-700 dark:text-gray-200 whitespace-nowrap">
                                {{ $activity->created_at?->isoFormat('L LTS') }}
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $activities->links() }}
        </div>
    @endif
</div>
