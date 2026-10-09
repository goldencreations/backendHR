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

        /*
         * Notifications reuse Laravel's own database-notification table
         * rather than defining a parallel one. A second table of the same
         * name would collide with the framework's, which already backs the
         * database channel via type / notifiable_* / data / read_at.
         *
         * Using it also removes the need for a separate recipients table:
         * read_at is already per recipient, which is exactly the semantics
         * the unread badge in the UI needs.
         *
         * actor_user_id and subject_* are added for the domain feed, so a
         * notification can name who acted and deep-link to the record that
         * triggered it.
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Polymorphic pointer to the originating record (leave_request,
            // contract, money_request, ...) so a notification can deep-link.
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['notifiable_type', 'read_at'], 'notifications_unread_index');
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
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('bank_accounts');
    }
};
