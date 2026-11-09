{{ __('Come join the team at :clinic', ['clinic' => $clinicName]) }}

{{ __('Hi there,') }}

@if ($inviterName)
{{ $inviterName }} {{ __('has invited you to join :clinic on :app.', ['clinic' => $clinicName, 'app' => $appName]) }}
@else
{{ __('You’ve been invited to join :clinic on :app.', ['clinic' => $clinicName, 'app' => $appName]) }}
@endif

@if ($hasAccount)
{{ __('You already have an account, so one click is all it takes:') }}
@else
{{ __('Open the invitation, choose a password, and you’re in:') }}
@endif

{{ $url }}

{{ __('You’ll join as :role. An admin can change your role later.', ['role' => $roleName]) }}
{{ __('This invitation works until :date.', ['date' => $expiresAt->format('F j, Y')]) }}

{{ __('Not expecting this? You can safely ignore this email; nothing changes unless you accept.') }}
