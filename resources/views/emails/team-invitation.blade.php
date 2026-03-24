@component('mail::message')
{{ __('app.team_invitation', ['team' => $invitation->team->name]) }}

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('app.team_invitation_no_account') }}

@component('mail::button', ['url' => route('register')])
{{ __('app.create_account') }}
@endcomponent

{{ __('app.team_invitation_has_account') }}

@else
{{ __('app.team_invitation_accept') }}
@endif


@component('mail::button', ['url' => $acceptUrl])
{{ __('app.accept_invitation') }}
@endcomponent

{{ __('app.team_invitation_discard') }}
@endcomponent
