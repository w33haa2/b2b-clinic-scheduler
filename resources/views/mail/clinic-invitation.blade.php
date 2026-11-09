{{--
    Clinic invitation email. Email clients ignore most CSS, so this is a
    table layout with inline styles: a warm off-white page, one white card,
    a serif headline and a single teal button, like the public site.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('You’re invited to join :clinic', ['clinic' => $clinicName]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f3ee;font-family:'Instrument Sans','Segoe UI',Helvetica,Arial,sans-serif;color:#1f2a2a;">
    {{-- Preview text shown next to the subject in the inbox --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        {{ $inviterName ? $inviterName.' would like you to join' : 'You’ve been invited to join' }} {{ $clinicName }} on {{ $appName }}.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6f3ee;">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                    {{-- Brand --}}
                    <tr>
                        <td style="padding:0 8px 20px 8px;font-size:15px;font-weight:600;letter-spacing:-0.01em;color:#0f5f58;">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:999px;background-color:#0f766e;margin-right:8px;vertical-align:middle;"></span>{{ $appName }}
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background-color:#ffffff;border:1px solid #e8e2d8;border-radius:16px;padding:40px 36px;">
                            <p style="margin:0 0 12px 0;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#0f766e;">
                                {{ __('An invitation for you') }}
                            </p>
                            <h1 style="margin:0 0 20px 0;font-family:'Newsreader',Georgia,'Times New Roman',serif;font-size:30px;line-height:1.2;font-weight:400;color:#14201f;">
                                {{ __('Come join the team at') }} <em style="color:#0f766e;">{{ $clinicName }}</em>
                            </h1>
                            <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#3d4847;">
                                {{ __('Hi there,') }}
                            </p>
                            <p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#3d4847;">
                                @if ($inviterName)
                                    {{ $inviterName }} {{ __('has invited you to join') }} <strong style="color:#14201f;">{{ $clinicName }}</strong> {{ __('on :app, where the team keeps its schedule, patients and billing in one place.', ['app' => $appName]) }}
                                @else
                                    {{ __('You’ve been invited to join') }} <strong style="color:#14201f;">{{ $clinicName }}</strong> {{ __('on :app, where the team keeps its schedule, patients and billing in one place.', ['app' => $appName]) }}
                                @endif
                            </p>
                            <p style="margin:0 0 28px 0;font-size:16px;line-height:1.6;color:#3d4847;">
                                @if ($hasAccount)
                                    {{ __('You already have an account, so one click is all it takes. :clinic will appear next to your other clinics.', ['clinic' => $clinicName]) }}
                                @else
                                    {{ __('It only takes a minute: open the invitation, choose a password, and you’re in.') }}
                                @endif
                            </p>

                            {{-- Button (a table so Outlook draws it) --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:10px;background-color:#0f766e;">
                                        <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">
                                            {{ $hasAccount ? __('Accept invitation') : __('Accept and set up my account') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:32px;border-top:1px solid #eee8de;">
                                <tr>
                                    <td style="padding-top:20px;font-size:14px;line-height:1.6;color:#6b7473;">
                                        {{ __('You’ll join as :role. An admin can change your role later.', ['role' => $roleName]) }}
                                        {{ __('This invitation works until :date.', ['date' => $expiresAt->format('F j, Y')]) }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top:16px;font-size:13px;line-height:1.6;color:#8a9291;">
                                        {{ __('If the button doesn’t work, copy this link into your browser:') }}<br>
                                        <a href="{{ $url }}" style="color:#0f766e;word-break:break-all;">{{ $url }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 8px 0 8px;font-size:12px;line-height:1.6;color:#8a9291;">
                            {{ __('Not expecting this? You can safely ignore this email; nothing changes unless you accept.') }}<br>
                            &copy; {{ date('Y') }} {{ $appName }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
