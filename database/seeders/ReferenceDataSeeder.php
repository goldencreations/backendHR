<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reference data matching the option lists in the frontend.
 *
 * Contracts  -> page.tsx:823
 * Documents  -> page.tsx:923 documentCategories
 * Leave      -> page.tsx:493
 * Money      -> page.tsx:1039 moneyKinds
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $contractTypes = [
            'Full time employment',
            'Fixed term contract',
            'Part time employment',
            'Probation contract',
            'Consultancy agreement',
        ];

        foreach ($contractTypes as $name) {
            DB::table('contract_types')->updateOrInsert(
                ['name' => $name],
                ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $documentCategories = [
            'Contract',
            'Identity',
            'Registration',
            'Payroll',
            'Leave',
            'Training',
            'Personal',
        ];

        foreach ($documentCategories as $name) {
            DB::table('document_categories')->updateOrInsert(
                ['name' => $name],
                ['slug' => Str::slug($name), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // Working-day entitlements per year. Annual matches the 21 days
        // the portal advertises; the rest follow the company policy.
        $leaveTypes = [
            ['name' => 'Annual leave', 'is_paid' => true, 'requires_document' => false, 'annual_allowance_days' => 21],
            ['name' => 'Sick leave', 'is_paid' => true, 'requires_document' => true, 'annual_allowance_days' => 14],
            ['name' => 'Personal leave', 'is_paid' => true, 'requires_document' => false, 'annual_allowance_days' => 5],
            ['name' => 'Medical leave', 'is_paid' => true, 'requires_document' => true, 'annual_allowance_days' => 14],
            ['name' => 'Parental leave', 'is_paid' => true, 'requires_document' => true, 'annual_allowance_days' => 60],
        ];

        foreach ($leaveTypes as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['name' => $type['name']],
                $type + [
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $moneyTypes = [
            ['name' => 'Salary advance', 'requires_receipt' => false],
            ['name' => 'Expense reimbursement', 'requires_receipt' => true],
            ['name' => 'Emergency advance', 'requires_receipt' => true],
            ['name' => 'Travel allowance', 'requires_receipt' => true],
            ['name' => 'Medical claim', 'requires_receipt' => true],
        ];

        foreach ($moneyTypes as $type) {
            DB::table('money_request_types')->updateOrInsert(
                ['name' => $type['name']],
                $type + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // Workspace settings. The advance limit replaces the hard-coded
        // advanceLimit = 2500000 in page.tsx:1040.
        $settings = [
            'advance_limit_minor' => 2500000,
            'currency' => 'TZS',
            'payroll_payment_day' => 28,
            'default_annual_leave_days' => null,
        ];

        foreach ($settings as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }
}
