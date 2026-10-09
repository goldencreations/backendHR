<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employee profile images, used by the avatar in Registration Info
 * (page.tsx:1199). Images are private and streamed the same way documents
 * are, so a passport-style photo is not publicly addressable.
 */
class EmployeeAvatarController extends Controller
{
    public function __construct(private readonly FileStorageService $files) {}

    public function show(Request $request, int $employee): Response
    {
        $model = Employee::findOrFail($employee);

        $allowed = $request->user()->isHr() || (int) $request->user()->employee_id === (int) $model->id;

        abort_unless($allowed, 403);

        if (! $model->profile_image_path || ! $this->files->exists($model->profile_image_path)) {
            abort(404);
        }

        return (new BinaryFileResponse(
            $this->files->absolutePath($model->profile_image_path)
        ))
            ->headers->set('Content-Type', 'image/jpeg')
            ->headers->set('X-Content-Type-Options', 'nosniff')
            ->headers->set('Cache-Control', 'private, max-age=3600');
    }

    public function store(Request $request, int $employee): JsonResponse
    {
        if (! $request->user()->isHr()) {
            return response()->json(['message' => 'Only HR staff can change a profile image.'], 403);
        }

        $model = Employee::findOrFail($employee);

        $request->validate([
            'file' => ['required', 'file', 'max:'.FileStorageService::IMAGE_MAX_KB],
        ]);

        try {
            $stored = $this->files->store(
                $request->file('file'),
                FileStorageService::FOLDER_AVATARS,
                FileStorageService::IMAGE_EXTENSIONS,
                FileStorageService::IMAGE_MAX_KB
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $previous = $model->profile_image_path;

        $model->forceFill(['profile_image_path' => $stored['path']])->save();

        if ($previous) {
            $this->files->delete($previous);
        }

        return response()->json([
            'message' => 'Profile image updated.',
            'path' => $stored['path'],
            'url' => route('api.employees.avatar', ['employee' => $model->id]),
        ]);
    }
}
