<?php

namespace App\Http\Controllers;

use App\Support\PublicMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function storeImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'image', 'max:5120'],
            'directory' => ['nullable', 'string', 'max:64'],
        ]);

        $directory = preg_replace('/[^a-z0-9_\-\/]/i', '', (string) ($validated['directory'] ?? 'media'));
        $directory = trim($directory, '/') ?: 'media';
        $subdir = $directory.'/'.now()->format('Ym');

        $file = $validated['file'];
        $extension = $file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg';
        $filename = Str::uuid()->toString().'.'.strtolower($extension);

        $path = $file->storeAs($subdir, $filename, 'public');

        return response()->json([
            'message' => 'success',
            'data' => [
                'path' => $path,
                'url' => PublicMedia::url($path),
            ],
        ]);
    }
}
