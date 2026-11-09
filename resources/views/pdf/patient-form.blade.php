@php
    /**
     * The clinic's patient form, printable. With a $patient it prints their
     * answers; without one it prints ruled lines and tick boxes to fill in
     * by hand.
     */
    $isBlank = $patient === null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $isBlank ? 'Patient form' : $patient->first_name.' '.$patient->last_name }}</title>
    <style>
        @page { margin: 48px 52px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #23211d; line-height: 1.5; }
        .muted { color: #6b675e; }
        .accent { color: #1f6f6a; }
        h1 { font-size: 26px; font-weight: normal; margin: 0 0 2px; letter-spacing: -0.5px; }
        h2 { font-size: 9px; text-transform: uppercase; letter-spacing: 0.8px; color: #6b675e; font-weight: normal;
             margin: 26px 0 10px; padding-bottom: 5px; border-bottom: 1px solid #e4e2dc; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .field { padding: 0 0 13px; vertical-align: top; }
        .label { font-size: 9.5px; color: #6b675e; margin-bottom: 3px; }
        .required { color: #b3261e; }
        .answer { border-bottom: 1px solid #d9d6ce; min-height: 15px; padding-bottom: 2px; }
        .answer.filled { border-bottom-color: #efeee9; color: #23211d; }
        .box { min-height: 44px; }
        .choice { margin-right: 14px; white-space: nowrap; }
        .hint { font-size: 9px; color: #9a958a; margin-top: 2px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 9px; color: #9a958a; }
        .sign { margin-top: 34px; }
        .sign td { width: 50%; padding-top: 30px; }
        .sign .rule { border-top: 1px solid #d9d6ce; padding-top: 4px; font-size: 9px; color: #6b675e; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>Patient form</h1>
                <div class="muted">{{ $isBlank ? 'Please complete every field marked with an asterisk.' : $patient->first_name.' '.$patient->last_name }}</div>
            </td>
            <td style="text-align: right;">
                <div style="font-size: 13px; font-weight: bold;" class="accent">{{ $clinic->name }}</div>
                @if ($clinic->address)
                    <div class="muted">{{ $clinic->address }}</div>
                @endif
                <div class="muted">{{ now($clinic->timezone ?? 'UTC')->format('j M Y') }}</div>
            </td>
        </tr>
    </table>

    @foreach ($sections as $section => $fields)
        <h2>{{ $section }}</h2>
        <table>
            @foreach ($fields as $field)
                @php $answer = $answers[$field->key] ?? ''; @endphp
                <tr>
                    <td class="field">
                        <div class="label">
                            {{ $field->label }}@if ($field->is_required)<span class="required"> *</span>@endif
                        </div>

                        @if ($field->patient_form_field_type_id->hasOptions() && $isBlank)
                            <div>
                                @foreach ($field->options ?? [] as $option)
                                    <span class="choice">&#9744; {{ $option }}</span>
                                @endforeach
                            </div>
                        @else
                            <div class="answer {{ $answer === '' ? '' : 'filled' }} {{ $field->patient_form_field_type_id->code() === 'TEXTAREA' ? 'box' : '' }}">
                                {{ $answer }}
                            </div>
                        @endif

                        @if ($field->help_text)
                            <div class="hint">{{ $field->help_text }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    @if ($isBlank)
        <table class="sign">
            <tr>
                <td><div class="rule">Patient signature</div></td>
                <td><div class="rule">Date</div></td>
            </tr>
        </table>
    @endif

    <div class="footer">{{ $clinic->name }} · {{ $isBlank ? 'Patient form' : 'Patient record' }}</div>
</body>
</html>
