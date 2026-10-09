<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Absolute URLs are pinned to APP_URL rather than the incoming request.
 *
 * The deployment forwards to the container without a Host header, so
 * Request::getHost() is empty and route() produced "https:/api/..." with a
 * single slash. That URL is unusable, and it reached the client in document
 * and avatar responses.
 */
class GeneratedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_urls_use_the_configured_application_url(): void
    {
        $root = rtrim((string) config('app.url'), '/');

        $this->assertNotSame('', $root);

        $url = route('api.files.download', ['document' => 1]);

        $this->assertStringStartsWith($root.'/', $url);
        // Guards the specific regression: a bare scheme breaks the URL.
        $this->assertDoesNotMatchRegularExpression('#https?:/(?!/)#', $url);
    }

    public function test_urls_stay_usable_when_the_request_carries_no_host(): void
    {
        config(['app.url' => 'https://hr-api.goldencreations.online']);

        // Re-boot the provider so the pinned root takes effect.
        $this->app->register(AppServiceProvider::class, true);
        (new AppServiceProvider($this->app))->boot();

        $url = url('/api/files/1/download');

        $this->assertSame('https://hr-api.goldencreations.online/api/files/1/download', $url);
        $this->assertDoesNotMatchRegularExpression('#https?:/(?!/)#', $url);
    }

    public function test_the_spec_server_url_is_absolute(): void
    {
        $spec = app(OpenApiGenerator::class)->generate();

        $this->assertNotSame('', $spec['servers'][0]['url']);
        $this->assertDoesNotMatchRegularExpression('#https?:/(?!/)#', $spec['servers'][0]['url']);
    }
}
