<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Super Admin dashboard + fraud signals (document/phase/14 §Admin Dashboard,
 * §Fraud Detection). Read-only aggregates.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    /** GET /admin/dashboard */
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->admin->dashboard(), 'Dashboard loaded.');
    }

    /** GET /admin/fraud */
    public function fraud(): JsonResponse
    {
        return ApiResponse::success($this->admin->fraud(), 'Fraud signals loaded.');
    }
}
