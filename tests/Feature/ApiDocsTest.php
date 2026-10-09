<?php

namespace Tests\Feature;

use App\Support\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OpenAPI document is generated from the registered route table, so the
 * documentation cannot drift from the API: a path that exists is documented,
 * and a path that is not documented does not exist.
 */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    private function spec(): array
    {
        return app(OpenApiGenerator::class)->generate();
    }

    public function test_the_spec_endpoint_serves_valid_openapi(): void
    {
        $response = $this->getJson('/api/docs.json')->assertOk();

        $spec = $response->json();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('GoldenHR API', $spec['info']['title']);
        $this->assertNotEmpty($spec['paths']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function test_the_docs_viewer_is_served(): void
    {
        $html = $this->get('/api-docs')->assertOk()->getContent();

        $this->assertStringContainsString('swagger-ui', $html);
        // Assets must resolve locally, not from a CDN.
        $this->assertStringContainsString('/api/docs/assets/', $html);
        $this->assertStringNotContainsString('petstore', $html);
    }

    public function test_the_initializer_points_at_the_local_spec(): void
    {
        $js = $this->get('/api/docs/assets/swagger-initializer.js')->assertOk()->getContent();

        $this->assertStringContainsString('/api/docs.json', $js);
        $this->assertStringNotContainsString('petstore.swagger.io', $js);
    }

    public function test_every_registered_api_route_is_documented(): void
    {
        $documented = array_keys($this->spec()['paths']);

        $registered = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route) => str_contains($route->uri(), 'sanctum/'))
            ->map(fn ($route) => '/'.$route->uri())
            ->reject(fn ($uri) => str_starts_with($uri, '/api/docs'))
            ->unique()
            ->values()
            ->all();

        $missing = array_values(array_diff($registered, $documented));

        $this->assertSame([], $missing, 'Undocumented API routes: '.implode(', ', $missing));
    }

    public function test_operations_carry_a_summary_and_responses(): void
    {
        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertArrayHasKey('summary', $operation, "$method $path has no summary");
                $this->assertNotEmpty($operation['summary'], "$method $path has an empty summary");
                $this->assertArrayHasKey('responses', $operation, "$method $path has no responses");
                $this->assertArrayHasKey('200', $operation['responses'], "$method $path has no 200 response");
            }
        }
    }

    public function test_secured_endpoints_are_documented_as_secured(): void
    {
        $paths = $this->spec()['paths'];

        // Login is the one public write endpoint.
        $this->assertSame([], $paths['/api/auth/login']['post']['security'] ?? null);

        // Everything else inherits bearer auth.
        $this->assertArrayNotHasKey('security', $paths['/api/employees']['get']);
    }

    public function test_path_parameters_are_declared(): void
    {
        $paths = $this->spec()['paths'];

        $parameters = collect($paths['/api/employees/{employee}']['get']['parameters'])
            ->pluck('name')
            ->all();

        $this->assertContains('employee', $parameters);
    }

    public function test_login_documents_its_request_body(): void
    {
        $schema = $this->spec()['paths']['/api/auth/login']['post']['requestBody'];

        $properties = $schema['content']['application/json']['schema']['properties'];

        $this->assertArrayHasKey('email', $properties);
        $this->assertArrayHasKey('password', $properties);
    }

    public function test_the_document_upload_endpoint_documents_a_binary_body(): void
    {
        $schema = $this->spec()['paths']['/api/documents']['post']['requestBody'];

        $file = $schema['content']['multipart/form-data']['schema']['properties']['file'];

        $this->assertSame('binary', $file['format']);
    }

    /**
     * Asset serving must not be usable to read arbitrary files.
     */
    public function test_asset_route_rejects_path_traversal(): void
    {
        $this->get('/api/docs/assets/..%2F..%2Fcomposer.json')->assertNotFound();
        $this->get('/api/docs/assets/nothing-here.js')->assertNotFound();
    }

    public function test_a_real_asset_is_served(): void
    {
        $this->get('/api/docs/assets/swagger-ui.css')->assertOk();
    }
}
