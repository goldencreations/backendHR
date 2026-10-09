<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll runs and payslips.
 *
 * gross / total_deductions / net are MySQL generated columns, so the
 * arithmetic the frontend performs in buildGross() is enforced by the
 * database and cannot drift from the stored values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'paid'])->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Cached run totals; the payslip rows remain authoritative.
            $table->bigInteger('gross_total_minor')->default(0);
            $table->bigInteger('net_total_minor')->default(0);
            $table->unsignedInteger('payslip_count')->default(0);

            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
            $table->index(['period_year', 'status']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // Earnings
            $table->bigInteger('basic_minor')->default(0);
            $table->bigInteger('overtime_minor')->default(0);
            $table->bigInteger('bonus_minor')->default(0);
            $table->bigInteger('allowance_minor')->default(0);

            // Tanzania statutory deductions: PAYE and NSSF pension.
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('pension_minor')->default(0);
            $table->bigInteger('other_deduction_minor')->default(0);

            $table->char('currency', 3)->default('TZS');
            $table->enum('payment_method', ['bank_transfer', 'mobile_money'])->default('bank_transfer');
            $table->enum('status', ['pending_approval', 'approved', 'paid'])->default('pending_approval');
            $table->date('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['run_id', 'employee_id']);
            $table->index(['employee_id', 'period_year', 'period_month']);
        });

        // Database-enforced totals.
        DB::statement(
            'ALTER TABLE payslips
             ADD COLUMN gross_minor BIGINT
             GENERATED ALWAYS AS (basic_minor + overtime_minor + bonus_minor + allowance_minor) STORED'
        );

        DB::statement(
            'ALTER TABLE payslips
             ADD COLUMN total_deductions_minor BIGINT
             GENERATED ALWAYS AS (tax_minor + pension_minor + other_deduction_minor) STORED'
        );

        DB::statement(
            'ALTER TABLE payslips
             ADD COLUMN net_minor BIGINT
             GENERATED ALWAYS AS (gross_minor - total_deductions_minor) STORED'
        );

        DB::statement('CREATE INDEX payslips_generated_net ON payslips (net_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};