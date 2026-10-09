<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money requests (advances, expense claims) and the payment rail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_request_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('money_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('money_request_type_id')->constrained('money_request_types')->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('TZS');
            $table->text('reason')->nullable();
            $table->date('needed_by')->nullable();
            $table->enum('payment_method', ['bank_transfer', 'mobile_money'])->default('mobile_money');

            $table->enum('status', ['pending', 'approved', 'paid', 'declined'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('receipt_number', 32)->nullable();

            $table->timestamps();

            $table->index(['status', 'needed_by']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_requests');
        Schema::dropIfExists('money_request_types');
    }
};