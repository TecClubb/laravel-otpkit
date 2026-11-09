<x-mail::message>
{{ __('otpkit::mail.intro') }}

<x-mail::panel>
<div style="font-size: 28px; font-weight: 700; letter-spacing: 6px; text-align: center;">{{ $code }}</div>
</x-mail::panel>

{{ trans_choice('otpkit::mail.expires', $expiresInMinutes, ['minutes' => $expiresInMinutes]) }}

{{ __('otpkit::mail.ignore') }}
</x-mail::message>
