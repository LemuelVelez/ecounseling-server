<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentProfileController extends Controller
{
    /**
     * POST /student/profile/avatar
     *
     * Handle avatar upload for the authenticated student.
     *
     * Request (multipart/form-data):
     *   - avatar: image file (max 5 MB)
     *
     * Response JSON:
     *   {
     *     "message": string,
     *     "avatar_url": string,
     *     "user": User
     *   }
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            // 5 MB = 5120 KB
            'avatar' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $file = $data['avatar'];

        // Try S3 first, fall back to the local "public" disk if S3 isn't available.
        $useS3 = true;
        $diskName = 's3';

        try {
            // Try to get the S3 disk. This will fail if the Flysystem AWS adapter
            // (league/flysystem-aws-s3-v3) is not installed or misconfigured.
            $disk = Storage::disk($diskName);

            // Touch the driver to force instantiation and trigger class loading.
            $disk->getDriver();
        } catch (\Throwable $e) {
            Log::error('[student.avatar] Failed to initialize S3 disk, falling back to "public" disk.', [
                'exception' => $e,
            ]);

            $useS3 = false;
            $diskName = 'public';
            $disk = Storage::disk($diskName);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename  = 'avatars/' . $user->id . '/' . now()->format('YmdHis') . '-' . Str::random(16) . '.' . $extension;

        if ($useS3) {
            // IMPORTANT:
            // For S3 with Object Ownership = Bucket owner enforced (ACLs disabled),
            // do NOT set ACLs/visibility here. Public read will come from the bucket policy.
            $disk->putFileAs('', $file, $filename);
        } else {
            // Local "public" disk (storage/app/public). This will be served via
            // APP_URL/storage after running `php artisan storage:link`.
            $disk->putFileAs('', $file, $filename);

            // Optionally ensure it's publicly visible on the local filesystem.
            if (method_exists($disk, 'setVisibility')) {
                $disk->setVisibility($filename, 'public');
            }
        }

        // Build URL depending on which disk we're using.
        $avatarUrl = $disk->url($filename);

        // Persist on the user record
        $user->avatar_url = $avatarUrl;
        $user->save();

        return response()->json([
            'message'    => $useS3
                ? 'Avatar updated successfully (stored in S3).'
                : 'Avatar updated successfully (stored locally because S3 is not available).',
            'avatar_url' => $avatarUrl,
            'user'       => $user,
        ]);
    }
}