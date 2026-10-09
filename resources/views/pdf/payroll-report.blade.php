@extends('pdf.layout')

@section('title', 'Payroll Report')
@section('doc-title', 'Payroll Report')
@section('doc-ref', $run->period_label)
@section('foot-ref', 'Run #'.$run->id)

@section('body')
    <table class="grid">
        <tr>
            <td>
                <p class="eyebrow">Period</p>
                <table class="kv">
                    <tr><td class="k">Payroll run</td><td class="v">{{ $run->period_label }}</td></tr>
                    <tr><td class="k">Status</td><td class="v"><span class="pill {{ $run->status === 'paid' ? 'ok' : '' }}">{{ ucfirst(str_replace('_', ' ', (string) $run->status)) }}</span></td></tr>
                </table>
            </td>
            <td>
                <p class="eyebrow">Totals</p>
                <table class="kv">
                    <tr><td class="k">Payslips</td><td class="v">{{ $run->payslip_count }}</td></tr>
                    <tr><td class="k">Gross total</td><td class="v">TSh {{ number_format($run->gross_total_minor) }}</td></tr>
                    <tr><td class="k">Net total</td><td class="v">TSh {{ number_format($run->net_total_minor) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section" style="margin-top: 14px;">
        <div class="section-title">Payslips</div>
        <table class="data">
            <tr>
                <th>Ref</th>
                <th>Employee</th>
                <th>Department</th>
                <th class="right">Gross</th>
                <th class="right">Deductions</th>
                <th class="right">Net</th>
                <th>Status</th>
            </tr>
            @foreach ($payslips as $payslip)
                <tr>
                    <td class="nowrap">{{ $payslip->reference }}</td>
                    <td>{{ $payslip->employee?->full_name }}</td>
                    <td>{{ $payslip->employee?->department?->name ?? '—' }}</td>
                    <td class="money">{{ number_format($payslip->gross_minor) }}</td>
                    <td class="money">{{ number_format($payslip->total_deductions_minor) }}</td>
                    <td class="money">{{ number_format($payslip->net_minor) }}</td>
                    <td>{{ ucfirst((string) $payslip->status) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">Totals</td>
                <td class="money">{{ number_format($run->gross_total_minor) }}</td>
                <td class="money">{{ number_format($run->gross_total_minor - $run->net_total_minor) }}</td>
                <td class="money">{{ number_format($run->net_total_minor) }}</td>
                <td></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Deduction breakdown</div>
        <table class="data">
            <tr><th>Component</th><th class="right">Amount (TSh)</th></tr>
            <tr><td>PAYE income tax</td><td class="money">{{ number_format($payslips->sum('tax_minor')) }}</td></tr>
            <tr><td>NSSF pension</td><td class="money">{{ number_format($payslips->sum('pension_minor')) }}</td></tr>
            <tr><td>Other deductions</td><td class="money">{{ number_format($payslips->sum('other_deduction_minor')) }}</td></tr>
            <tr><td>Earnings — overtime</td><td class="money">{{ number_format($payslips->sum('overtime_minor')) }}</td></tr>
            <tr><td>Earnings — bonus</td><td class="money">{{ number_format($payslips->sum('bonus_minor')) }}</td></tr>
            <tr><td>Earnings — allowances</td><td class="money">{{ number_format($payslips->sum('allowance_minor')) }}</td></tr>
        </table>
    </div>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 45%;"><div class="sign-line">Prepared by</div></td>
            <td></td>
            <td style="width: 45%;"><div class="sign-line">Approved by</div></td>
        </tr>
    </table>
@endsection