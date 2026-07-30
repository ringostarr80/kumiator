@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 focus:border-indigo-600 dark:focus:border-indigo-300 focus:ring-indigo-600 dark:focus:ring-indigo-300 rounded-md shadow-xs']) !!}>
