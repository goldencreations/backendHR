<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards against a store configured in .env having no table behind it.
 *
 * SESSION_DRIVER=database with no sessions table made every web route fail,
 * including the API documentation. That is only visible at runtime, so it
 * is asserted here instead.
 */
class StoreConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_session_table_exists_when_sessions_are_stored_in_the_database(): void
    {
        // Forced regardless of the local driver: the point is that the
        // migration creates the table for the production configuration, and
        // the test environment uses file drivers so it would otherwise skip.
        config(['session.driver' => 'database']);

        $this->assertTrue(
            Schema::hasTable(config('session.table', 'sessions')),
            'SESSION_DRIVER=database but the sessions table is missing.'
        );
    }

    public function test_the_cache_tables_exist_when_the_cache_is_stored_in_the_database(): void
    {
        config(['cache.default' => 'database']);

        $this->assertTrue(Schema::hasTable('cache'), 'CACHE_STORE=database but cache is missing.');
        $this->assertTrue(Schema::hasTable('cache_locks'), 'CACHE_STORE=database but cache_locks is missing.');
    }

    public function test_queue_tables_exist_when_the_queue_uses_the_database(): void
    {
        config(['queue.default' => 'database']);

        $this->assertTrue(Schema::hasTable('jobs'), 'QUEUE_CONNECTION=database but jobs is missing.');
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'QUEUE_CONNECTION=database but failed_jobs is missing.');
    }

    public function test_the_token_table_exists_for_sanctum(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
    }

    /**
     * A smoke test for the web middleware group, which is where the missing
     * session table surfaced.
     */
    public function test_a_web_route_responds(): void
    {
        $this->get('/')->assertOk();
    }
}
