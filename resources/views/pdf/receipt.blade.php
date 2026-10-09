@extends('pdf.layout')

@section('title', 'Payment Receipt')
@section('doc-title', 'Official Receipt')
@section('doc-ref', $request->receipt_number)
@section('foot-ref', $request->reference)

@section('body')
    <table class="box accent" style="margin-bottom: 14px;">
        <tr>
            <td>
                <p class="eyebrow">Receipt number</p>
                <div style="font-size: 15px; font-weight: bold;">{{ $request->receipt_number }}</div>
            </td>
            <td class="right">
                <p class="eyebrow">Status</p>
                <span class="pill {{ $request->status === 'paid' ? 'ok' : ($request->status === 'declined' ? 'off' : '') }}">{{ ucfirst((string) $request->status) }}</span>
            </td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td>
                <p class="eyebrow">Received from</p>
                <table class="kv">
                    <tr><td class="k">Employee</td><td class="v">{{ $request->employee?->full_name }}</td></tr>
                    <tr><td class="k">Employee code</td><td class="v">{{ $request->employee?->employee_code }}</td></tr>
                    <tr><td class="k">Department</td><td class="v">{{ $request->employee?->department?->name ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <p class="eyebrow">Payment</p>
                <table class="kv">
                    <tr><td class="k">Request type</td><td class="v">{{ $request->type?->name }}</td></tr>
                    <tr><td class="k">Method</td><td class="v">{{ str_replace('_', ' ', ucfirst((string) $request->payment_method)) }}</td></tr>
                    <tr><td class="k">Needed by</td><td class="v">{{ $request->needed_by?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="k">Paid on</td><td class="v">{{ $request->paid_at?->format('d M Y') ?? 'Pending' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="box" style="margin-top: 12px;">
        <tr>
            <td style="width: 60%;">
                <p class="eyebrow">Amount {{ $request->status === 'paid' ? 'paid' : 'requested' }}</p>
                <div style="font-size: 18px; font-weight: bold;">{{ $request->currency }} {{ number_format($request->amount_minor) }}</div>
            </td>
            <td class="right">
                <p class="eyebrow">Reference</p>
                <div style="font-weight: bold;">{{ $request->reference }}</div>
            </td>
        </tr>
    </table>

    @if ($request->reason)
        <div class="section" style="margin-top: 14px;">
            <div class="section-title">Reason given</div>
            <p style="margin: 0;">{{ $request->reason }}</p>
        </div>
    @endif

    <div class="section">
        <div class="section-title">Decision</div>
        <table class="kv">
            <tr><td class="k">Decided by</td><td class="v">{{ $request->decidedBy?->name ?? 'Pending' }}</td></tr>
            <tr><td class="k">Decided on</td><td class="v">{{ $request->decided_at?->format('d M Y') ?? '—' }}</td></tr>
            @if ($request->decision_note)
                <tr><td class="k">Note</td><td class="v">{{ $request->decision_note }}</td></tr>
            @endif
        </table>
    </div>

    <p class="muted" style="font-size: 8px;">
        This receipt confirms the request above and its recorded decision.
    </p>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 45%;"><div class="sign-line">Employee signature</div></td>
            <td></td>
            <td style="width: 45%;"><div class="sign-line">Authorised signature</div></td>
        </tr>
    </table>
@endsection