<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the sessions and password_reset_tokens tables.
 *
 * They were dropped when the users migration was rewritten in the schema
 * phase. The API never needed them because it is token authenticated, but
 * routes served through the web middleware group do, and the production
 * configuration stores sessions in the database. Every web route failed
 * with "Table sessions doesn't exist" while the API endpoints kept
 * working, which is why the gap went unnoticed.
 *
 * This is a separate migration rather than an edit to the users migration,
 * because that one is already recorded as run in the deployed database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
