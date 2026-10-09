<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['hr_admin', 'hr_officer', 'employee'])->default('employee');
            // FK to employees is added in a later migration: employees does not
            // exist yet at this point in the sequence. Only this direction is
            // persisted - MySQL has no DEFERRABLE constraint, so the reverse
            // link cannot participate in the cycle.
            $table->foreignId('employee_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
