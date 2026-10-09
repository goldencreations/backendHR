<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contracts and the document hub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('contract_type_id')->constrained('contract_types')->restrictOnDelete();

            $table->date('start_date');
            // NULL end_date means open-ended. The frontend encodes this as
            // durationDays === 0, which is stored as NULL rather than a zero.
            $table->date('end_date')->nullable();
            $table->unsignedInteger('duration_days')->nullable();

            $table->bigInteger('base_salary_minor')->default(0);
            $table->char('currency', 3)->default('TZS');

            $table->text('description')->nullable();
            $table->text('termination_terms')->nullable();

            $table->enum('status', [
                'draft',
                'pending_signature',
                'active',
                'renewal_due',
                'expired',
                'terminated',
            ])->default('draft');

            $table->timestamp('sent_for_signature_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'end_date']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('document_categories')->nullOnDelete();

            $table->string('name', 255);
            // Relative path only. A public absolute URL is never stored, so
            // files are always streamed through an authorised route and the
            // host can change without a data migration.
            $table->string('disk_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->bigInteger('size_bytes')->default(0);

            $table->enum('status', ['verified', 'pending_review', 'expiring_soon'])->default('pending_review');
            $table->date('expires_at')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'category_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('document_categories');
        Schema::dropIfExists('contract_types');
    }
};