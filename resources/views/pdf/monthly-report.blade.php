@extends('pdf.layout')

@section('title', 'Monthly Report')
@section('doc-title', 'Monthly Report')
@section('doc-ref', $year)
@section('foot-ref', 'FY'.$year)

@section('body')
    <table class="grid">
        <tr>
            <td>
                <p class="eyebrow">Headcount</p>
                <table class="kv">
                    <tr><td class="k">Active</td><td class="v">{{ $totals['headcount'] }}</td></tr>
                    <tr><td class="k">Joined in {{ $year }}</td><td class="v">{{ $totals['joined'] }}</td></tr>
                    <tr><td class="k">Terminated in {{ $year }}</td><td class="v">{{ $totals['terminated'] }}</td></tr>
                    <tr><td class="k">Departments</td><td class="v">{{ $totals['departments'] }}</td></tr>
                </table>
            </td>
            <td>
                <p class="eyebrow">Money</p>
                <table class="kv">
                    <tr><td class="k">Payroll net</td><td class="v">TSh {{ number_format($totals['payroll_net']) }}</td></tr>
                    <tr><td class="k">Payroll gross</td><td class="v">TSh {{ number_format($totals['payroll_gross']) }}</td></tr>
                    <tr><td class="k">Money requested</td><td class="v">TSh {{ number_format($totals['money_requested']) }}</td></tr>
                    <tr><td class="k">Money paid</td><td class="v">TSh {{ number_format($totals['money_paid']) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section" style="margin-top: 14px;">
        <div class="section-title">Month by month</div>
        <table class="data">
            <tr>
                <th>Month</th>
                <th class="right">Payroll net</th>
                <th class="right">Money req.</th>
                <th class="right">Leave appr.</th>
                <th class="right">Leave decl.</th>
                <th class="right">Contracts</th>
                <th class="right">Headcount</th>
            </tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="nowrap">{{ $row['month'] }}</td>
                    <td class="money">{{ number_format($row['payroll_net']) }}</td>
                    <td class="money">{{ number_format($row['money_requested']) }}</td>
                    <td class="money">{{ $row['leave_approved'] }}</td>
                    <td class="money">{{ $row['leave_declined'] }}</td>
                    <td class="money">{{ $row['contracts'] }}</td>
                    <td class="money">{{ $row['headcount'] }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td>Totals</td>
                <td class="money">{{ number_format($totals['payroll_net']) }}</td>
                <td class="money">{{ number_format($totals['money_requested']) }}</td>
                <td class="money">{{ array_sum(array_column($rows, 'leave_approved')) }}</td>
                <td class="money">{{ array_sum(array_column($rows, 'leave_declined')) }}</td>
                <td class="money">{{ array_sum(array_column($rows, 'contracts')) }}</td>
                <td class="money">{{ $totals['headcount'] }}</td>
            </tr>
        </table>
    </div>

    <p class="muted" style="font-size: 8px;">
        Aggregated from GoldenHR payroll, leave, money request and contract records.
        Headcount is point-in-time at each month end.
    </p>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 45%;"><div class="sign-line">Prepared by</div></td>
            <td></td>
            <td style="width: 45%;"><div class="sign-line">Reviewed by</div></td>
        </tr>
    </table>
@endsection