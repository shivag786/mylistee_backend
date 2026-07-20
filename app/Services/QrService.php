<?php

namespace App\Services;

use App\Models\Business;
use App\Models\QrCode;

/**
 * Owns the permanent business QR code (document/phase/02 §QR Code Rules).
 * The QR always encodes the public business-profile URL and never changes.
 * The image itself is rendered client-side from `url`; this service manages the
 * record and its usage counters.
 */
class QrService
{
    /** Create the permanent primary QR for a business (idempotent). */
    public function createForBusiness(Business $business): QrCode
    {
        return $business->qrCode()->firstOrCreate(
            ['type' => 'primary'],
            [
                'url' => $this->profileUrl($business->slug),
                'status' => 'active',
                'download_count' => 0,
                'scan_count' => 0,
            ],
        );
    }

    /** The public URL a scan should open: {frontend}/b/{slug}. */
    public function profileUrl(string $slug): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$base}/b/{$slug}";
    }

    public function incrementDownloads(QrCode $qr): void
    {
        $qr->increment('download_count');
    }

    public function incrementScans(QrCode $qr): void
    {
        $qr->increment('scan_count');
    }
}
