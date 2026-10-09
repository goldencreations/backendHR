<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes an uploaded file to the private hr_files disk.
 *
 * Filenames are generated rather than taken from the client: a user-supplied
 * name can contain traversal sequences, can collide, and can carry a second
 * extension (report.pdf.exe). Only the original name is kept, in the
 * database, for display.
 */
class FileStorageService
{
    /**
     * Extensions accepted for employee documents. Chosen from the categories
     * the frontend already offers (page.tsx:923) plus the CV formats the
     * employee form advertises (page.tsx:247).
     */
    public const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png',
    ];

    public const DOCUMENT_MAX_KB = 10240;

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const IMAGE_MAX_KB = 4096;

    /**
     * MIME types allowed per extension. Checking both stops a renamed
     * executable from passing on its name alone.
     */
    private const MIME_ALLOWLIST = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    public const FOLDER_EMPLOYEES = 'employees';

    public const FOLDER_CONTRACTS = 'contracts';

    public const FOLDER_DOCUMENTS = 'documents';

    public const FOLDER_AVATARS = 'avatars';

    /**
     * @param  list<string>  $allowedExtensions
     * @return array{path: string, mime_type: string, size_bytes: int, original_name: string}
     */
    public function store(
        UploadedFile $file,
        string $folder,
        array $allowedExtensions = self::DOCUMENT_EXTENSIONS,
        int $maxKb = self::DOCUMENT_MAX_KB
    ): array {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        // getClientOriginalExtension reads the last dot-segment, but a name
        // like "passport.php.jpg" still needs the extension checked against
        // the real detected type rather than trusted on its own.
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException(
                "Unsupported file type '.{$extension}'. Allowed: ".implode(', ', $allowedExtensions).'.'
            );
        }

        $detectedMime = $this->detectMime($file);
        $allowedMimes = self::MIME_ALLOWLIST[$extension] ?? [];

        if (! in_array($detectedMime, $allowedMimes, true)) {
            throw new RuntimeException(
                "The file contents do not match a .{$extension} file."
            );
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);

        if ($sizeKb > $maxKb) {
            throw new RuntimeException("The file is too large. Maximum size is {$maxKb} KB.");
        }

        $name = Str::uuid()->toString().'.'.$extension;

        // Year/month keeps any single directory from accumulating thousands
        // of entries as the document count grows.
        $directory = $folder.'/'.now()->format('Y/m');
        $path = $directory.'/'.$name;

        Storage::disk('hr_files')->putFileAs($directory, $file, $name);

        if (! Storage::disk('hr_files')->exists($path)) {
            throw new RuntimeException('The file could not be saved.');
        }

        return [
            'path' => $path,
            'mime_type' => $detectedMime,
            'size_bytes' => (int) $file->getSize(),
            'original_name' => $this->sanitiseDisplayName($originalName),
        ];
    }

    public function delete(string $path): void
    {
        $disk = Storage::disk('hr_files');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }

    public function exists(string $path): bool
    {
        return Storage::disk('hr_files')->exists($path);
    }

    /**
     * Absolute path on disk, for streaming a response.
     */
    public function absolutePath(string $path): string
    {
        return Storage::disk('hr_files')->path($path);
    }

    /**
     * Inspects the actual bytes rather than trusting the client's claim.
     */
    private function detectMime(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_buffer($finfo, $contents);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return $file->getMimeType() ?: 'application/octet-stream';
    }

    /**
     * Keeps a readable name for the UI without letting it influence the path
     * on disk. Control characters and path separators are stripped.
     */
    private function sanitiseDisplayName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'document';
        $name = str_replace(['/', '\\'], '-', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'document';
        }

        return Str::limit($name, 255, '');
    }
}
