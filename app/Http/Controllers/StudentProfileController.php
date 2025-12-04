<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Use the "s3" disk configured to read:
        //   - AWS_REGION
        //   - S3_BUCKET_NAME
        //   - AWS_ACCESS_KEY_ID
        //   - AWS_SECRET_ACCESS_KEY
        $disk = Storage::disk('s3');

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename  = 'avatars/' . $user->id . '/' . now()->format('YmdHis') . '-' . Str::random(16) . '.' . $extension;

        // Store with public visibility so we can generate a URL
        $disk->putFileAs('', $file, $filename, [
            'visibility' => 'public',
        ]);

        $avatarUrl = $disk->url($filename);

        // Persist on the user record
        $user->avatar_url = $avatarUrl;
        $user->save();

        return response()->json([
            'message'    => 'Avatar updated successfully.',
            'avatar_url' => $avatarUrl,
            'user'       => $user,
        ]);
    }
}