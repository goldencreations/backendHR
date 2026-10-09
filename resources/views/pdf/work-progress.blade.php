@extends('pdf.layout')

@section('title', 'Work Progress')
@section('doc-title', 'Work Progress')
@section('doc-ref', $year)
@section('foot-ref', $employee->employee_code)

@section('body')
    <table class="box accent" style="margin-bottom: 14px;">
        <tr>
            <td>
                <p class="eyebrow">{{ $year }} analysis</p>
                <div style="font-size: 16px; font-weight: bold;">{{ $employee->full_name }}</div>
                <div class="muted">{{ $employee->jobRole?->title ?? 'No role' }} &middot; {{ $employee->department?->name ?? 'No department' }}</div>
            </td>
            <td class="right" style="width: 34%;">
                <p class="eyebrow">Net pay received</p>
                <div style="font-size: 15px; font-weight: bold;">TSh {{ number_format($summary['year_net_minor']) }}</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Summary</div>
        <table class="grid">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="k">Payslips</td><td class="v">{{ $summary['payslip_count'] }}</td></tr>
                        <tr><td class="k">Leave days taken</td><td class="v">{{ $summary['leave_days'] }}</td></tr>
                        <tr><td class="k">Money requested</td><td class="v">TSh {{ number_format($summary['money_requested_minor']) }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Leave requests</td><td class="v">{{ $summary['leave_requests'] }}</td></tr>
                        <tr><td class="k">Money requests</td><td class="v">{{ $summary['money_requests'] }}</td></tr>
                        <tr><td class="k">Average net pay</td><td class="v">TSh {{ number_format($summary['payslip_count'] > 0 ? intdiv($summary['year_net_minor'], $summary['payslip_count']) : 0) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Pay by month</div>
        @if ($payslips->isEmpty())
            <p class="muted" style="margin: 0;">No payslips recorded for {{ $year }}.</p>
        @else
            <table class="data">
                <tr><th>Month</th><th class="right">Gross</th><th class="right">Deductions</th><th class="right">Net</th><th>Status</th></tr>
                @foreach ($payslips as $payslip)
                    <tr>
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($payslip->period_year.'-'.str_pad((string) $payslip->period_month, 2, '0', STR_PAD_LEFT))->format('M Y') }}</td>
                        <td class="money">{{ number_format($payslip->gross_minor) }}</td>
                        <td class="money">{{ number_format($payslip->total_deductions_minor) }}</td>
                        <td class="money">{{ number_format($payslip->net_minor) }}</td>
                        <td>{{ ucfirst((string) $payslip->status) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Leave requests</div>
        @if ($leaves->isEmpty())
            <p class="muted" style="margin: 0;">No leave requests recorded for {{ $year }}.</p>
        @else
            <table class="data">
                <tr><th>Ref</th><th>Type</th><th>From</th><th>To</th><th class="right">Days</th><th>Status</th></tr>
                @foreach ($leaves as $leave)
                    <tr>
                        <td class="nowrap">{{ $leave->reference }}</td>
                        <td>{{ $leave->leaveType?->name }}</td>
                        <td class="nowrap">{{ $leave->start_date?->format('d M Y') }}</td>
                        <td class="nowrap">{{ $leave->end_date?->format('d M Y') }}</td>
                        <td class="money">{{ rtrim(rtrim(number_format((float) $leave->days, 2), '0'), '.') }}</td>
                        <td>{{ ucfirst((string) $leave->status) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <p class="muted" style="font-size: 8px;">
        Confidential. Generated from the employee's own GoldenHR record.
    </p>
@endsection