<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight health/connectivity endpoint. Confirms the API is reachable and
 * the database connection is alive — used by the frontend to verify the
 * frontend<->backend handshake (Milestone 1 deliverable, document/phase/17).
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $database = 'unavailable';
        }

        return ApiResponse::success(
            data: [
                'status' => 'ok',
                'service' => config('app.name'),
                'environment' => config('app.env'),
                'database' => $database,
                'time' => now()->toIso8601String(),
                'version' => 'v1',
            ],
            message: 'Listee API is running.',
        );
    }
}
