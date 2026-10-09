<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Generated URLs are pinned to APP_URL, not derived from the incoming
 * request.
 *
 * The deployment forwards to the container without a Host header, so the
 * request host is empty and Laravel produced "https:/api/..." with a single
 * slash. That is why APP_URL is authoritative.
 */
class ProxyUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the environment the deployment uses, then re-boot the provider
        // so the root is applied.
        config(['app.url' => 'https://hr-api.goldencreations.online']);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_document_urls_are_generated_from_app_url(): void
    {
        Storage::fake('hr_files');

        $hr = User::factory()->hrAdmin()->create();
        $employee = Employee::factory()->create();

        $body = "%PDF-1.4\n".str_repeat("% padding\n", 40);

        $url = $this->actingAs($hr)->post('/api/documents', [
            'employee_id' => $employee->id,
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', $body),
        ])->assertCreated()->json('document.url');

        $this->assertStringStartsWith('https://hr-api.goldencreations.online/api/files/', $url);
        $this->assertStringEndsWith('/download', $url);
        $this->assertDoesNotMatchRegularExpression('#https?:/(?!/)#', $url);
    }

    /**
     * A plain-HTTP hop inside the container must not downgrade the URL.
     */
    public function test_the_scheme_is_not_downgraded_to_http(): void
    {
        Storage::fake('hr_files');

        $hr = User::factory()->hrAdmin()->create();
        $employee = Employee::factory()->create();

        $body = "%PDF-1.4\n".str_repeat("% padding\n", 40);

        $url = $this->actingAs($hr)->post('/api/documents', [
            'employee_id' => $employee->id,
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', $body),
        ])->assertCreated()->json('document.url');

        $this->assertStringStartsWith('https://', $url);
    }

    public function test_avatar_urls_are_generated_from_app_url(): void
    {
        $employee = Employee::factory()->create();
        $hr = User::factory()->hrAdmin()->create();

        $url = $this->actingAs($hr)
            ->getJson('/api/employees')
            ->assertOk()
            ->json('data.0.avatar_url');

        // No profile image stored, so the avatar URL is null.
        $this->assertNull($url);

        $this->assertStringStartsWith('https://', route('api.employees.avatar', ['employee' => $employee->id]));
    }
}
