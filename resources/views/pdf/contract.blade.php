@extends('pdf.layout')

@section('title', 'Employment Contract')
@section('doc-title', 'Contract Summary')
@section('doc-ref', $contract->reference)
@section('foot-ref', $contract->reference)

@section('body')
    <table class="grid">
        <tr>
            <td>
                <p class="eyebrow">Employee</p>
                <table class="kv">
                    <tr><td class="k">Name</td><td class="v">{{ $contract->employee?->full_name }}</td></tr>
                    <tr><td class="k">Employee code</td><td class="v">{{ $contract->employee?->employee_code }}</td></tr>
                    <tr><td class="k">Department</td><td class="v">{{ $contract->employee?->department?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Role</td><td class="v">{{ $contract->employee?->jobRole?->title ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <p class="eyebrow">Agreement</p>
                <table class="kv">
                    <tr><td class="k">Type</td><td class="v">{{ $contract->contractType?->name }}</td></tr>
                    <tr><td class="k">Start date</td><td class="v">{{ $contract->start_date?->format('d M Y') }}</td></tr>
                    <tr><td class="k">End date</td><td class="v">{{ $contract->end_date?->format('d M Y') ?? 'Open ended' }}</td></tr>
                    <tr><td class="k">Status</td><td class="v"><span class="pill {{ $contract->status === 'active' ? 'ok' : 'off' }}">{{ ucfirst(str_replace('_', ' ', (string) $contract->status)) }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="box accent" style="margin-top: 12px;">
        <tr>
            <td style="width: 60%;">
                <p class="eyebrow">Base salary</p>
                <div style="font-size: 16px; font-weight: bold;">{{ $contract->currency }} {{ number_format($contract->base_salary_minor) }}</div>
            </td>
            <td class="right">
                <p class="eyebrow">Signed</p>
                <div style="font-weight: bold;">{{ $contract->signed_at?->format('d M Y') ?? 'Awaiting signature' }}</div>
            </td>
        </tr>
    </table>

    @if ($contract->description)
        <div class="section" style="margin-top: 14px;">
            <div class="section-title">Scope and duties</div>
            <p style="margin: 0;">{{ $contract->description }}</p>
        </div>
    @endif

    @if ($contract->termination_terms)
        <div class="section">
            <div class="section-title">Notice and termination</div>
            <p style="margin: 0;">{{ $contract->termination_terms }}</p>
        </div>
    @endif

    <div class="section">
        <div class="section-title">Signature record</div>
        <table class="kv">
            <tr><td class="k">Sent for signature</td><td class="v">{{ $contract->sent_for_signature_at?->format('d M Y') ?? 'Not sent' }}</td></tr>
            <tr><td class="k">Signed</td><td class="v">{{ $contract->signed_at?->format('d M Y H:i') ?? 'Pending' }}</td></tr>
        </table>
    </div>

    <p class="muted" style="font-size: 8px;">
        This is a generated summary of the agreement record held by GoldenHR.
        The signed document remains the authoritative copy.
    </p>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 45%;"><div class="sign-line">Employee signature</div></td>
            <td></td>
            <td style="width: 45%;"><div class="sign-line">Authorised signature</div></td>
        </tr>
    </table>
@endsection