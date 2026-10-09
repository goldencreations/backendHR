<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The API container terminates plain HTTP while Cloudflare terminates TLS in
 * front of it. If Laravel does not honour X-Forwarded-Proto it believes
 * every request arrived over http and returns http:// URLs, which a browser
 * on the https frontend blocks as mixed content.
 */
class ProxyUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_urls_are_generated_with_the_forwarded_https_scheme(): void
    {
        Storage::fake('hr_files');

        $hr = User::factory()->hrAdmin()->create();
        $employee = Employee::factory()->create();

        $body = "%PDF-1.4\n".str_repeat("% padding\n", 40);

        $response = $this->actingAs($hr)->post('/api/documents', [
            'employee_id' => $employee->id,
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', $body),
        ], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'hr-api.goldencreations.online',
        ]);

        $response->assertCreated();

        $url = $response->json('document.url');

        $this->assertStringStartsWith('https://', $url, 'Generated URL must use the forwarded https scheme.');
        $this->assertStringContainsString('hr-api.goldencreations.online', $url);
    }

    public function test_urls_stay_http_when_nothing_forwards_https(): void
    {
        Storage::fake('hr_files');

        $hr = User::factory()->hrAdmin()->create();
        $employee = Employee::factory()->create();

        $body = "%PDF-1.4\n".str_repeat("% padding\n", 40);

        $url = $this->actingAs($hr)->post('/api/documents', [
            'employee_id' => $employee->id,
            'file' => UploadedFile::fake()->createWithContent('contract.pdf', $body),
        ])->assertCreated()->json('document.url');

        $this->assertStringStartsWith('http://', $url);
    }
}
