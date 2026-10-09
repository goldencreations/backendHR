<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * UploadedFile::fake()->create() produces an empty file, which the
     * content-type check correctly rejects. These helpers write real magic
     * bytes so the sniffing path is genuinely exercised.
     */
    private function fakePdf(string $name = 'document.pdf', int $approxKb = 8): UploadedFile
    {
        $body = "%PDF-1.4\n".str_repeat('% padding to reach the requested size '.str_repeat('-', 40)."\n", max(1, $approxKb * 12));

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function fakePng(string $name = 'image.png'): UploadedFile
    {
        // 1x1 transparent PNG, then padding in an ancillary chunk region.
        $body = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent($name, $body.$body);
    }

    private function hrAdmin(): User
    {
        return User::factory()->hrAdmin()->create();
    }

    private function employeeUser(?Employee $employee = null): User
    {
        $employee ??= Employee::factory()->create();

        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $employee->id,
        ]);
    }

    private function upload(User $hr, Employee $employee, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($hr)->postJson('/api/documents', [
            'employee_id' => $employee->id,
            'file' => $file ?? $this->fakePdf(),
        ]);
    }

    public function test_hr_uploads_a_document(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $category = \DB::table('document_categories')->insertGetId([
            'name' => 'Contract',
            'slug' => 'contract',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = $this->fakePdf('contract.pdf', 20);

        $response = $this->actingAs($this->hrAdmin())->postJson('/api/documents', [
            'employee_id' => $employee->id,
            'category_id' => $category,
            'file' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.name', 'contract.pdf')
            ->assertJsonPath('document.category', 'Contract')
            ->assertJsonPath('document.type', 'PDF')
            ->assertJsonPath('document.status', 'pending_review')
            ->assertJsonStructure(['document' => ['id', 'reference', 'url', 'preview_url', 'size', 'mime_type']]);

        $document = EmployeeDocument::firstOrFail();

        $this->assertSame('contract.pdf', $document->name);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(filesize($file->getRealPath()), $document->size_bytes);
        Storage::disk('hr_files')->assertExists($document->disk_path);
    }

    /**
     * A traversal attempt must not escape the disk, and the stored name must
     * be a generated UUID rather than anything the client supplied.
     */
    public function test_stored_path_is_generated_and_confined_to_the_disk(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee, $this->fakePdf('../../escape.pdf'))->assertCreated();

        $document = EmployeeDocument::firstOrFail();

        $this->assertStringNotContainsString('..', $document->disk_path);
        $this->assertMatchesRegularExpression(
            '/^documents\/\d{4}\/\d{2}\/[0-9a-f-]{36}\.pdf$/',
            $document->disk_path
        );

        // The traversal name is kept only for display, and is flattened.
        $this->assertStringNotContainsString('/', $document->name);
    }

    public function test_extension_must_be_allowed(): void
    {
        Storage::fake('hr_files');

        $this->upload($this->hrAdmin(), Employee::factory()->create(), $this->fakePdf('payload.php'))
            ->assertStatus(422);
    }

    /**
     * The extension is checked against the bytes actually uploaded, so a
     * renamed text file cannot pass by naming itself .pdf.
     */
    public function test_content_must_match_the_extension(): void
    {
        Storage::fake('hr_files');

        $file = UploadedFile::fake()->createWithContent('invoice.pdf', 'just some plain text, definitely not a pdf');

        $this->upload($this->hrAdmin(), Employee::factory()->create(), $file)->assertStatus(422);
    }

    public function test_upload_requires_authentication(): void
    {
        $employee = Employee::factory()->create();

        $this->postJson('/api/documents', [
            'employee_id' => $employee->id,
            'file' => $this->fakePdf(),
        ])->assertUnauthorized();
    }

    public function test_employee_cannot_upload_documents(): void
    {
        Storage::fake('hr_files');

        $this->actingAs($this->employeeUser())->postJson('/api/documents', [
            'employee_id' => 1,
            'file' => $this->fakePdf(),
        ])->assertStatus(403);
    }

    public function test_hr_can_download_a_document(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee)->assertCreated();

        $document = EmployeeDocument::firstOrFail();

        $response = $this->actingAs($this->hrAdmin())->get("/api/files/{$document->id}/download");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // Never a cached public URL.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_owner_can_download_their_own_document(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee)->assertCreated();

        $document = EmployeeDocument::firstOrFail();

        $this->actingAs($this->employeeUser($employee))
            ->get("/api/files/{$document->id}/download")
            ->assertOk();
    }

    /**
     * The rule the Documents screen states in its own footer: only HR and
     * the employee may open the file.
     */
    public function test_another_employee_cannot_download_the_document(): void
    {
        Storage::fake('hr_files');

        $owner = Employee::factory()->create();
        $intruder = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $owner)->assertCreated();

        $document = EmployeeDocument::firstOrFail();

        $this->actingAs($this->employeeUser($intruder))
            ->get("/api/files/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_download_requires_authentication(): void
    {
        $document = EmployeeDocument::factory()->create();

        $this->get("/api/files/{$document->id}/download")->assertUnauthorized();
    }

    public function test_an_employee_only_sees_their_own_documents_in_the_listing(): void
    {
        Storage::fake('hr_files');

        $mine = Employee::factory()->create();
        $theirs = Employee::factory()->create();

        $hr = $this->hrAdmin();

        $this->upload($hr, $mine, $this->fakePdf('mine.pdf'))->assertCreated();
        $this->upload($hr, $theirs, $this->fakePdf('theirs.pdf'))->assertCreated();

        $this->assertCount(2, $this->actingAs($hr)->getJson('/api/documents')->json('data'));

        $listed = $this->actingAs($this->employeeUser($mine))->getJson('/api/documents')->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('mine.pdf', $listed[0]['name']);
    }

    /**
     * Supplying someone else's employee_id must not widen the result set.
     */
    public function test_employee_cannot_list_another_employees_documents_by_filtering(): void
    {
        Storage::fake('hr_files');

        $mine = Employee::factory()->create();
        $theirs = Employee::factory()->create();

        $hr = $this->hrAdmin();

        $this->upload($hr, $mine, $this->fakePdf('mine.pdf'))->assertCreated();
        $this->upload($hr, $theirs, $this->fakePdf('theirs.pdf'))->assertCreated();

        $response = $this->actingAs($this->employeeUser($mine))
            ->getJson("/api/documents?employee_id={$theirs->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('mine.pdf', $response->json('data.0.name'));
    }

    public function test_replacing_a_document_removes_the_previous_file(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee, $this->fakePdf('old.pdf'))->assertCreated();

        $document = EmployeeDocument::firstOrFail();
        $originalPath = $document->disk_path;

        $this->actingAs($this->hrAdmin())
            ->post("/api/documents/{$document->id}/replace", [
                'file' => $this->fakePdf('new.pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('document.name', 'new.pdf')
            ->assertJsonPath('document.status', 'pending_review');

        Storage::disk('hr_files')->assertMissing($originalPath);
        Storage::disk('hr_files')->assertExists($document->fresh()->disk_path);
    }

    public function test_hr_can_delete_a_document_and_its_file(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee, $this->fakePdf('gone.pdf'))->assertCreated();

        $document = EmployeeDocument::firstOrFail();
        $path = $document->disk_path;

        $this->actingAs($this->hrAdmin())
            ->deleteJson("/api/documents/{$document->id}")
            ->assertOk();

        $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
        Storage::disk('hr_files')->assertMissing($path);
    }

    public function test_employee_cannot_delete_a_document(): void
    {
        $document = EmployeeDocument::factory()->create();
        $owner = Employee::find($document->employee_id);

        $this->actingAs($this->employeeUser($owner))
            ->deleteJson("/api/documents/{$document->id}")
            ->assertStatus(403);
    }

    public function test_downloading_a_row_whose_file_is_missing_returns_404(): void
    {
        $document = EmployeeDocument::factory()->create();

        $this->actingAs($this->hrAdmin())
            ->get("/api/files/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_an_image_upload_is_accepted_and_reported_as_an_image(): void
    {
        Storage::fake('hr_files');

        $employee = Employee::factory()->create();

        $this->upload($this->hrAdmin(), $employee, $this->fakePng('passport.png'))
            ->assertCreated()
            ->assertJsonPath('document.type', 'Image')
            ->assertJsonPath('document.mime_type', 'image/png');

        $this->assertDatabaseHas('employee_documents', ['mime_type' => 'image/png']);
    }

    /**
     * An image renamed with a .pdf extension must be refused, since the
     * detected content type is what the allowlist is checked against.
     */
    public function test_an_image_cannot_be_uploaded_under_a_pdf_name(): void
    {
        Storage::fake('hr_files');

        $this->upload($this->hrAdmin(), Employee::factory()->create(), $this->fakePng('sneaky.pdf'))
            ->assertStatus(422);

        $this->assertDatabaseCount('employee_documents', 0);
    }
}
