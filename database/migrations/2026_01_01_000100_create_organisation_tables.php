<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation core: departments, roles, people and assignment history.
 *
 * Reference data (contract_types, document_categories, leave_types,
 * money_request_types) is seeded separately rather than hard-coded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('job_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('title', 150);
            $table->enum('level', ['lead', 'senior', 'mid', 'junior', 'intern'])->default('mid');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A role belongs to exactly one department. MySQL has no partial
            // indexes, so this is a plain composite unique key.
            $table->unique(['department_id', 'title']);
            $table->index(['department_id', 'is_active']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('first_name', 120);
            $table->string('last_name', 120);
            $table->string('email', 191)->unique();
            $table->string('phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();

            // Tanzania statutory identifiers.
            $table->string('tin', 32)->nullable();
            $table->string('nida_number', 64)->nullable();

            $table->text('address')->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();

            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('job_role_id')->nullable()->constrained('job_roles')->restrictOnDelete();

            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'temporary'])->default('full_time');
            $table->date('start_date')->nullable();
            $table->date('hire_date')->nullable();

            // Money is stored as an integer, never a formatted string.
            $table->bigInteger('base_salary_minor')->default(0);
            $table->char('currency', 3)->default('TZS');

            $table->string('profile_image_path', 500)->nullable();
            $table->enum('status', ['active', 'on_leave', 'suspended', 'terminated'])->default('active');
            $table->date('termination_date')->nullable();

            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->index('status');
        });

        Schema::create('department_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['department_id', 'is_primary']);
        });

        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('job_role_id')->constrained('job_roles')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->boolean('is_current')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            // Intended invariant: one current row per employee. MySQL cannot
            // express a partial unique index (WHERE is_current), so it is
            // enforced in EmployeeAssignmentService inside a transaction.
            $table->index(['employee_id', 'is_current']);
        });

        // Complete the users <-> employees link now that both tables exist.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('department_leads');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('job_roles');
        Schema::dropIfExists('departments');
    }
};
