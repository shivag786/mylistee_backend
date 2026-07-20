<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Builds the platform's standard API envelope so every endpoint returns an
 * identical shape (document/phase/11 §Response Format, phase/04 §Response Format):
 *
 *   { "success": bool, "message": string, "data": mixed, "meta": object|null, "errors": object|null }
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'Operation completed successfully.',
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => null,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(
        string $message = 'Something went wrong. Please try again.',
        ?array $errors = null,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => null,
            'errors' => $errors,
        ], $status);
    }
}
