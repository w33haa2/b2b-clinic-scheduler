@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $total = $invoice->sessionLineItems->reduce(
        fn ($sum, $item) => $sum->plus($item->amount()),
        \Brick\Math\BigDecimal::zero(),
    );
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->reference() }}</title>
    <style>
        @page { margin: 48px 52px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #23211d; line-height: 1.5; }
        .muted { color: #6b675e; }
        .accent { color: #1f6f6a; }
        h1 { font-size: 26px; font-weight: normal; margin: 0 0 2px; letter-spacing: -0.5px; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .meta td { padding: 0; vertical-align: top; width: 50%; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b675e; margin-bottom: 2px; }
        .items { margin-top: 28px; }
        .items th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #6b675e; font-weight: normal; padding: 8px 0; border-bottom: 1px solid #e4e2dc; }
        .items td { padding: 9px 0; border-bottom: 1px solid #efeee9; vertical-align: top; }
        .num, .items th.num { text-align: right; white-space: nowrap; }
        .total td { border-bottom: none; padding-top: 14px; font-size: 14px; }
        .notes { margin-top: 28px; padding: 12px 14px; background: #f6f5f2; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 9px; color: #9a958a; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>Invoice</h1>
                <div class="muted">{{ $invoice->reference() }}</div>
            </td>
            <td style="text-align: right;">
                <div style="font-size: 13px; font-weight: bold;" class="accent">{{ $clinic->name }}</div>
                @if ($clinic->address)
                    <div class="muted">{!! nl2br(e($clinic->address)) !!}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta" style="margin-top: 32px;">
        <tr>
            <td>
                <div class="label">Bill to</div>
                <div style="font-weight: bold;">{{ $invoice->patient?->first_name }} {{ $invoice->patient?->last_name }}</div>
                @if ($invoice->patient?->email)<div class="muted">{{ $invoice->patient->email }}</div>@endif
                @if ($invoice->patient?->phone)<div class="muted">{{ $invoice->patient->phone }}</div>@endif
            </td>
            <td style="text-align: right;">
                <div class="label">Issued</div>
                <div>{{ $invoice->issued_at->format('j F Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Service</th>
                <th>Session</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->sessionLineItems as $item)
                <tr>
                    <td>{{ $item->lineItem?->name }} <span class="muted">{{ $item->lineItem?->item_code }}</span></td>
                    <td class="muted">
                        {{ $item->sessionMember?->clinicSession?->starts_at->setTimezone($clinic->timezone)->format('j M Y, H:i') }}
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->amount()) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="4"><strong>Total</strong></td>
                <td class="num"><strong>{{ $money((string) $total->toScale(2)) }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if ($invoice->notes)
        <div class="notes">{!! nl2br(e($invoice->notes)) !!}</div>
    @endif

    <div class="footer">{{ $clinic->name }} · {{ $invoice->reference() }} · Generated {{ now()->setTimezone($clinic->timezone)->format('j M Y') }}</div>
</body>
</html>
