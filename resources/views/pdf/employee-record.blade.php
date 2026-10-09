@extends('pdf.layout')

@section('title', 'Employee Record')
@section('doc-title', 'Employee Record')
@section('doc-ref', $employee->employee_code)
@section('foot-ref', $employee->employee_code)

@section('body')
    <table class="box accent" style="margin-bottom: 14px;">
        <tr>
            <td>
                <p class="eyebrow">{{ $employee->department?->name ?? 'No department' }}</p>
                <div style="font-size: 16px; font-weight: bold;">{{ $employee->full_name }}</div>
                <div class="muted">{{ $employee->jobRole?->title ?? 'No role' }} &middot; {{ ucfirst(str_replace('_', ' ', (string) $employee->employment_type)) }}</div>
            </td>
            <td class="right" style="width: 30%;">
                <span class="pill {{ $employee->status === 'active' ? 'ok' : 'off' }}">{{ ucfirst((string) $employee->status) }}</span>
                <div class="muted" style="margin-top: 4px;">Hired {{ $employee->hire_date?->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Personal details</div>
        <table class="grid">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="k">Full legal name</td><td class="v">{{ $employee->full_name }}</td></tr>
                        <tr><td class="k">Date of birth</td><td class="v">{{ $employee->date_of_birth?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><td class="k">Email</td><td class="v">{{ $employee->email }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Phone</td><td class="v">{{ $employee->phone ?? '—' }}</td></tr>
                        <tr><td class="k">NIDA number</td><td class="v">{{ $employee->nida_number ?? '—' }}</td></tr>
                        <tr><td class="k">TIN</td><td class="v">{{ $employee->tin ?? '—' }}</td></tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <table class="kv">
                        <tr><td class="k">Residential address</td><td class="v">{{ $employee->address ?? '—' }}</td></tr>
                        <tr><td class="k">Emergency contact</td><td class="v">{{ $employee->emergency_contact_name ?? '—' }}{{ $employee->emergency_contact_phone ? ' · '.$employee->emergency_contact_phone : '' }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Employment</div>
        <table class="grid">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="k">Department</td><td class="v">{{ $employee->department?->name ?? '—' }}</td></tr>
                        <tr><td class="k">Role</td><td class="v">{{ $employee->jobRole?->title ?? '—' }}</td></tr>
                        <tr><td class="k">Employment type</td><td class="v">{{ ucfirst(str_replace('_', ' ', (string) $employee->employment_type)) }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Start date</td><td class="v">{{ $employee->start_date?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><td class="k">Hire date</td><td class="v">{{ $employee->hire_date?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><td class="k">Monthly salary</td><td class="v">{{ $employee->currency }} {{ number_format($employee->base_salary_minor) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    @if ($bankAccounts->isNotEmpty())
        <div class="section">
            <div class="section-title">Payment details</div>
            <table class="data">
                <tr><th>Type</th><th>Institution</th><th>Account</th><th>Verified</th></tr>
                @foreach ($bankAccounts as $account)
                    <tr>
                        <td>{{ ucfirst((string) $account->type) }}</td>
                        <td>{{ $account->bank_name ?? '—' }}</td>
                        <td class="nowrap">{{ $account->account_number ?? '—' }}</td>
                        <td>{{ $account->is_verified ? 'Yes' : 'Pending' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($documents->isNotEmpty())
        <div class="section">
            <div class="section-title">Documents on file</div>
            <table class="data">
                <tr><th>Document</th><th>Category</th><th>Status</th></tr>
                @foreach ($documents as $document)
                    <tr>
                        <td>{{ $document->name }}</td>
                        <td>{{ $document->category?->name ?? '—' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', (string) $document->status)) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <p class="muted" style="font-size: 8px;">
        Confidential. Generated from the GoldenHR people directory.
    </p>
@endsection