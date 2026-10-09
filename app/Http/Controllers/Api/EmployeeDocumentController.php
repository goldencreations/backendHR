<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Services\FileStorageService;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeDocumentController extends Controller
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly ReferenceGenerator $references,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = EmployeeDocument::query()
            ->with(['employee:id,first_name,last_name', 'category:id,name,slug'])
            ->orderByDesc('created_at');

        // A non-HR caller is restricted to their own documents regardless of
        // any employee_id they try to supply.
        if (! $request->user()->isHr()) {
            $query->where('employee_id', $request->user()->employee_id);
        } elseif ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        if ($request->filled('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $request->string('category')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $documents = $query->paginate(min($request->integer('per_page', 25), 100));

        return response()->json([
            'data' => collect($documents->items())->map(fn (EmployeeDocument $d) => $this->payload($d))->all(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isHr()) {
            return response()->json(['message' => 'Only HR staff can attach documents.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'category_id' => ['nullable', 'integer', 'exists:document_categories,id'],
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
            'status' => ['nullable', 'in:verified,pending_review,expiring_soon'],
            'expires_at' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:'.FileStorageService::DOCUMENT_MAX_KB],
        ]);

        try {
            $stored = $this->files->store($request->file('file'), FileStorageService::FOLDER_DOCUMENTS);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $document = EmployeeDocument::create([
            'reference' => $this->references->next('employee_documents', 'DOC'),
            'employee_id' => $validated['employee_id'],
            'category_id' => $validated['category_id'] ?? null,
            'contract_id' => $validated['contract_id'] ?? null,
            'name' => $stored['original_name'],
            'disk_path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'status' => $validated['status'] ?? 'pending_review',
            'expires_at' => $validated['expires_at'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        $document->load(['employee:id,first_name,last_name', 'category:id,name,slug']);

        return response()->json([
            'message' => 'Document uploaded.',
            'document' => $this->payload($document),
        ], 201);
    }

    public function show(Request $request, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('view', $document);

        return response()->json(['document' => $this->payload($document)]);
    }

    /**
     * Streams the file rather than redirecting to a public URL, so access is
     * re-checked on every request and a URL can never outlive permission.
     */
    public function download(Request $request, EmployeeDocument $document): Response
    {
        Gate::authorize('download', $document);

        if (! $this->files->exists($document->disk_path)) {
            return response()->json([
                'message' => 'The stored file is missing. Re-upload the document.',
            ], 404);
        }

        $absolute = $this->files->absolutePath($document->disk_path);

        // setContentDisposition() returns null, so the headers are set on
        // the response rather than chained off that call.
        $response = new BinaryFileResponse($absolute);
        $response->setContentDisposition(
            $request->boolean('inline') ? 'inline' : 'attachment',
            $document->name,
            // Fallback for clients that cannot handle non-ASCII filenames.
            $this->asciiFallback($document->name)
        );

        // Never let the browser sniff a document into an executable type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function destroy(Request $request, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('delete', $document);

        $this->files->delete($document->disk_path);
        $document->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    /**
     * Replaces the stored bytes while keeping the same row and reference.
     */
    public function replace(Request $request, EmployeeDocument $document): JsonResponse
    {
        Gate::authorize('delete', $document);

        $request->validate([
            'file' => ['required', 'file', 'max:'.FileStorageService::DOCUMENT_MAX_KB],
        ]);

        try {
            $stored = $this->files->store($request->file('file'), FileStorageService::FOLDER_DOCUMENTS);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $previous = $document->disk_path;

        $document->update([
            'name' => $stored['original_name'],
            'disk_path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'status' => 'pending_review',
        ]);

        // Only unlink the old copy once the row points at the new one.
        $this->files->delete($previous);

        $document->load(['employee:id,first_name,last_name', 'category:id,name,slug']);

        return response()->json([
            'message' => 'Document replaced.',
            'document' => $this->payload($document),
        ]);
    }

    /**
     * The URL is built per response rather than stored, so changing hosts
     * needs no data migration and no access check is bypassed by a cached URL.
     */
    private function payload(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'reference' => $document->reference,
            'name' => $document->name,
            'employee' => $document->employee ? [
                'id' => $document->employee->id,
                'full_name' => $document->employee->full_name,
            ] : null,
            'category' => $document->category?->name,
            'category_slug' => $document->category?->slug,
            'type' => $this->typeLabel($document->mime_type),
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'size' => $this->humanSize($document->size_bytes),
            'status' => $document->status,
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_by' => $document->uploaded_by,
            'created_at' => $document->created_at?->toIso8601String(),
            'url' => route('api.files.download', ['document' => $document->id]),
            'preview_url' => route('api.files.download', ['document' => $document->id, 'inline' => 1]),
        ];
    }

    private function typeLabel(?string $mime): string
    {
        return str_starts_with((string) $mime, 'image/') ? 'Image' : 'PDF';
    }

    private function humanSize(int $bytes): string
    {
        $kb = $bytes / 1024;

        if ($kb >= 1024) {
            return round($kb / 1024, 1).' MB';
        }

        return round($kb).' KB';
    }

    private function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'document';

        return $ascii !== '' ? $ascii : 'document';
    }
}
