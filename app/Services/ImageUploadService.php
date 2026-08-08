<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploadService
{
    /**
     * Allowed MIME types for checkpoint images
     */
    protected const ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Allowed file extensions
     */
    protected const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Max file size in bytes (10MB)
     */
    protected const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Upload a checkpoint image
     *
     * @param UploadedFile $file
     * @param string $routeMapId
     * @param string $checkpointId
     * @return array ['url' => string, 'filename' => string]
     * @throws \Exception
     */
    public function store(UploadedFile $file, string $routeMapId, string $checkpointId): array
    {
        // Validate file
        $this->validateFile($file);

        // Generate filename
        $filename = $this->generateFileName($file, $routeMapId, $checkpointId);

        // Store file
        $path = "routemaps/{$routeMapId}/checkpoints/{$filename}";
        Storage::disk('public')->put($path, $file->getContent());

        // Return public URL
        return [
            'filename' => $filename,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    /**
     * Delete a checkpoint image
     *
     * @param string $routeMapId
     * @param string $filename
     * @return bool
     */
    public function delete(string $routeMapId, string $filename): bool
    {
        $path = "routemaps/{$routeMapId}/checkpoints/{$filename}";

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        return true; // No error if file doesn't exist
    }

    /**
     * Upload a photo attached to a saved activity (opgenomen route).
     *
     * @param UploadedFile $file
     * @param int|string $activityId
     * @return array ['url' => string, 'filename' => string]
     * @throws \Exception
     */
    public function storeForActivity(UploadedFile $file, $activityId): array
    {
        $this->validateFile($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = now()->format('YmdHis') . '-' . Str::random(8) . '.' . $extension;

        $path = "activities/{$activityId}/{$filename}";
        Storage::disk('public')->put($path, $file->getContent());

        return [
            'filename' => $filename,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    /**
     * Delete a photo attached to an activity.
     *
     * @param int|string $activityId
     * @param string $filename
     * @return bool
     */
    public function deleteForActivity($activityId, string $filename): bool
    {
        $path = "activities/{$activityId}/{$filename}";

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        return true;
    }

    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @throws \Exception
     */
    protected function validateFile(UploadedFile $file): void
    {
        // Check MIME type
        if (!in_array($file->getMimeType(), self::ALLOWED_TYPES)) {
            throw new \Exception('Alleen JPG, PNG of WebP toegestaan', 422);
        }

        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \Exception('Alleen JPG, PNG of WebP toegestaan', 422);
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \Exception('Bestand groter dan 10MB', 413);
        }

        // Additional validation: ensure file is actually an image
        if (!$this->isValidImage($file->getRealPath())) {
            throw new \Exception('Bestand is geen geldig afbeelding', 422);
        }
    }

    /**
     * Verify file is a valid image
     *
     * @param string $filePath
     * @return bool
     */
    protected function isValidImage(string $filePath): bool
    {
        try {
            $info = @getimagesize($filePath);
            return $info !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate unique filename
     *
     * @param UploadedFile $file
     * @param string $routeMapId
     * @param string $checkpointId
     * @return string
     */
    protected function generateFileName(UploadedFile $file, string $routeMapId, string $checkpointId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);

        return "{$checkpointId}-{$timestamp}-{$random}.{$extension}";
    }

    /**
     * Extract filename from URL
     *
     * @param string $url
     * @return string|null
     */
    public function getFilenameFromUrl(string $url): ?string
    {
        return basename($url);
    }
}
