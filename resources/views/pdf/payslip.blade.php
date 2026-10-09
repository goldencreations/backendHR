@extends('pdf.layout')

@section('title', 'Payslip')
@section('doc-title', 'Payslip')
@section('doc-ref', $payslip->reference)
@section('foot-ref', $payslip->reference)

@section('body')
    <table class="grid">
        <tr>
            <td>
                <p class="eyebrow">Employee</p>
                <table class="kv">
                    <tr><td class="k">Name</td><td class="v">{{ $payslip->employee?->full_name }}</td></tr>
                    <tr><td class="k">Employee code</td><td class="v">{{ $payslip->employee?->employee_code }}</td></tr>
                    <tr><td class="k">Department</td><td class="v">{{ $payslip->employee?->department?->name ?? '—' }}</td></tr>
                    <tr><td class="k">Role</td><td class="v">{{ $payslip->employee?->jobRole?->title ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <p class="eyebrow">Pay period</p>
                <table class="kv">
                    <tr><td class="k">Period</td><td class="v">{{ \Illuminate\Support\Carbon::parse($payslip->period_year.'-'.str_pad((string) $payslip->period_month, 2, '0', STR_PAD_LEFT))->format('F Y') }}</td></tr>
                    <tr><td class="k">Payment method</td><td class="v">{{ str_replace('_', ' ', ucfirst((string) $payslip->payment_method)) }}</td></tr>
                    <tr><td class="k">Paid on</td><td class="v">{{ $payslip->paid_at?->format('d M Y') ?? 'Pending' }}</td></tr>
                    <tr><td class="k">Status</td><td class="v"><span class="pill {{ $payslip->status === 'paid' ? 'ok' : 'off' }}">{{ ucfirst((string) $payslip->status) }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section" style="margin-top: 14px;">
        <div class="section-title">Earnings</div>
        <table class="data">
            <tr><th>Component</th><th class="right">Amount ({{ $payslip->currency }})</th></tr>
            <tr><td>Basic salary</td><td class="money">{{ number_format($payslip->basic_minor) }}</td></tr>
            <tr><td>Overtime</td><td class="money">{{ number_format($payslip->overtime_minor) }}</td></tr>
            <tr><td>Bonus</td><td class="money">{{ number_format($payslip->bonus_minor) }}</td></tr>
            <tr><td>Allowances</td><td class="money">{{ number_format($payslip->allowance_minor) }}</td></tr>
            <tr class="total-row">
                <td>Gross pay</td>
                <td class="money">{{ number_format($payslip->gross_minor) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Deductions</div>
        <table class="data">
            <tr><th>Component</th><th class="right">Amount ({{ $payslip->currency }})</th></tr>
            <tr><td>PAYE income tax</td><td class="money">{{ number_format($payslip->tax_minor) }}</td></tr>
            <tr><td>NSSF pension</td><td class="money">{{ number_format($payslip->pension_minor) }}</td></tr>
            <tr><td>Other deductions</td><td class="money">{{ number_format($payslip->other_deduction_minor) }}</td></tr>
            <tr class="total-row">
                <td>Total deductions</td>
                <td class="money">{{ number_format($payslip->total_deductions_minor) }}</td>
            </tr>
        </table>
    </div>

    <table class="box accent" style="margin-top: 6px;">
        <tr>
            <td style="width: 60%;">
                <p class="eyebrow">Net pay</p>
                <div style="font-size: 18px; font-weight: bold;">{{ $payslip->currency }} {{ number_format($payslip->net_minor) }}</div>
            </td>
            <td class="right">
                <p class="eyebrow">Reference</p>
                <div style="font-weight: bold;">{{ $payslip->reference }}</div>
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 12px; font-size: 8px;">
        Gross, deductions and net are computed by the database from the recorded
        components. This document is generated from the employee record held by
        GoldenHR People Operations and is confidential to the named employee.
    </p>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 45%;"><div class="sign-line">Employee signature</div></td>
            <td></td>
            <td style="width: 45%;"><div class="sign-line">Authorised signature</div></td>
        </tr>
    </table>
@endsection