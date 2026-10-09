<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-scoped support tables: banking, attendance, goals,
 * notifications and workspace settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('type', ['bank', 'mobile_money'])->default('bank');
            $table->string('bank_name', 120)->nullable();
            // Stored per the payment rail; a mobile money number sits in
            // account_number for the mobile_money type.
            $table->string('account_number', 64)->nullable();
            $table->string('account_name', 191)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'type']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('status', ['worked', 'leave', 'absent', 'holiday', 'weekend'])->default('worked');
            $table->decimal('worked_hours', 4, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter')->nullable();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->enum('status', ['not_started', 'in_progress', 'achieved', 'missed'])->default('not_started');
            $table->timestamps();

            $table->index(['employee_id', 'period_year']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', [
                'payroll',
                'money',
                'contract',
                'role',
                'department',
                'employee',
                'document',
                'leave',
                'security',
            ]);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Polymorphic pointer to the originating record (leave_request,
            // contract, money_request, ...) so a notification can deep-link.
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('kind');
        });

        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Read state is per recipient, not per notification.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id'], 'notification_recipients_unique');
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('bank_accounts');
    }
};