<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Http\Resources\QrCodeResource;
use App\Services\QrService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business QR code (document/phase/07 §QR Code, phase/11 §QR Endpoints). The
 * image is rendered client-side from `url`; this endpoint returns the record and
 * tracks download usage. The QR is permanent and never regenerated in M4.
 */
class QrController extends Controller
{
    public function __construct(private readonly QrService $qr) {}

    /** GET /business/qr — the owner's permanent QR record. */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        $qr = $business ? $this->qr->createForBusiness($business) : null;

        if ($qr === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        return ApiResponse::success(new QrCodeResource($qr), 'QR code.');
    }

    /** POST /business/qr/download — record a download (client renders the file). */
    public function download(Request $request): JsonResponse
    {
        $business = $request->user()->business();
        $qr = $business?->qrCode;

        if ($qr === null) {
            return ApiResponse::error('No business found for this account.', status: 404);
        }

        $this->qr->incrementDownloads($qr);

        return ApiResponse::success(new QrCodeResource($qr->fresh()), 'Download recorded.');
    }
}
