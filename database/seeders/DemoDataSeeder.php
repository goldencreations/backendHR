<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A small, coherent dataset matching the people the UI was designed around.
 *
 * Deliberately opt-in: it creates real logins with known passwords, so it
 * must never run automatically on a deploy. Use `db:seed --class=DemoDataSeeder`
 * against a local or staging database only.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! $this->command?->option('force')) {
            $this->command?->warn('Refusing to seed demo data in production. Pass --force to override.');

            return;
        }

        $departments = [
            'Engineering' => ['Engineering Lead', 'Frontend Engineer', 'QA Specialist'],
            'Design' => ['Product Designer', 'UX Researcher'],
            'Human Resources' => ['People Operations', 'Recruiter'],
            'Marketing' => ['Growth Manager', 'Content Strategist'],
        ];

        foreach ($departments as $name => $roles) {
            $department = Department::firstOrCreate(['name' => $name]);

            foreach ($roles as $title) {
                JobRole::firstOrCreate(
                    ['department_id' => $department->id, 'title' => $title],
                    ['level' => 'mid']
                );
            }
        }

        $people = [
            ['Amara', 'Okafor', 'Design', 'Product Designer', 'full_time', 4860000, '2023-05-12'],
            ['Noah', 'Williams', 'Engineering', 'Engineering Lead', 'full_time', 6420000, '2022-01-08'],
            ['Sofia', 'Martin', 'Human Resources', 'People Operations', 'contract', 3920000, '2023-08-21'],
            ['Liam', 'Chen', 'Marketing', 'Growth Manager', 'part_time', 3100000, '2024-03-15'],
        ];

        $employees = [];

        // Codes are allocated from whatever is free rather than counting
        // rows, so the seeder stays re-runnable against a database that
        // already holds employees.
        $nextCode = (int) (Employee::max('employee_code') ? substr((string) Employee::max('employee_code'), 3) : 0) + 1;

        foreach ($people as [$first, $last, $departmentName, $roleTitle, $type, $salary, $hired]) {
            $department = Department::where('name', $departmentName)->firstOrFail();
            $role = JobRole::where('department_id', $department->id)->where('title', $roleTitle)->firstOrFail();

            $employee = Employee::firstOrCreate(
                ['email' => strtolower($first.'.'.$last).'@goldenhr.com'],
                [
                    'employee_code' => 'GH-'.str_pad((string) $nextCode++, 4, '0', STR_PAD_LEFT),
                    'first_name' => $first,
                    'last_name' => $last,
                    'employment_type' => $type,
                    'base_salary_minor' => $salary,
                    'currency' => 'TZS',
                    'hire_date' => $hired,
                    'start_date' => $hired,
                    'status' => 'active',
                ]
            );

            $department->leads()->firstOrCreate(
                ['employee_id' => $employee->id],
                ['is_primary' => true]
            );

            $employees[] = [$employee, $department, $role];
        }

        // Portal logins so the demo is usable from both sides.
        foreach ($employees as [$employee, $department, $role]) {
            $password = 'DemoPass2026!';

            $user = User::firstOrCreate(
                ['email' => $employee->email],
                [
                    'name' => $employee->full_name,
                    'password' => Hash::make($password),
                    'role' => $employee->department_id === Department::where('name', 'Human Resources')->value('id')
                        ? User::ROLE_HR_OFFICER
                        : User::ROLE_EMPLOYEE,
                    'employee_id' => $employee->id,
                    'is_active' => true,
                ]
            );

            $employee->forceFill(['user_id' => $user->id])->save();

            $employee->assignments()->firstOrCreate(
                ['employee_id' => $employee->id],
                [
                    'job_role_id' => $role->id,
                    'department_id' => $department->id,
                    'is_current' => true,
                    'effective_from' => $employee->hire_date,
                ]
            );

            $this->command?->info("{$employee->email} / {$password}");
        }

        // Leave balances seeded from each type's entitlement.
        $year = (int) now()->format('Y');

        foreach ($employees as [$employee]) {
            foreach (LeaveType::whereNotNull('annual_allowance_days')->get() as $type) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                    ['entitled_days' => $type->annual_allowance_days, 'used_days' => 0, 'booked_days' => 0]
                );
            }
        }

        // One paid payroll run so the dashboards have real figures.
        $run = PayrollRun::firstOrCreate(
            ['period_year' => $year, 'period_month' => 6],
            ['status' => 'paid', 'paid_at' => now()]
        );

        if ($run->payslips()->count() === 0) {
            $sequence = 1;

            foreach ($employees as [$employee]) {
                Payslip::create([
                    'reference' => 'PR-'.$year.'-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT),
                    'run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'period_year' => $year,
                    'period_month' => 6,
                    'basic_minor' => $employee->base_salary_minor,
                    'overtime_minor' => 0,
                    'bonus_minor' => 0,
                    'allowance_minor' => 0,
                    'tax_minor' => (int) round($employee->base_salary_minor * 0.1),
                    'pension_minor' => (int) round($employee->base_salary_minor * 0.08),
                    'other_deduction_minor' => 0,
                    'status' => 'paid',
                    'paid_at' => now()->toDateString(),
                ]);
            }

            $totals = Payslip::where('run_id', $run->id)
                ->selectRaw('COALESCE(SUM(gross_minor),0) g, COALESCE(SUM(net_minor),0) n, COUNT(*) c')
                ->first();

            $run->update([
                'gross_total_minor' => (int) $totals->g,
                'net_total_minor' => (int) $totals->n,
                'payslip_count' => (int) $totals->c,
            ]);
        }
    }
}
