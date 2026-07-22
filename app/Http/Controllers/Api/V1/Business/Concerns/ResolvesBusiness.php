<?php

namespace App\Http\Controllers\Api\V1\Business\Concerns;

use App\Models\Business;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated owner's business, returning the standard 404
 * envelope when they haven't registered one yet. Keeps owner controllers thin
 * and consistent (Phase 7.2+).
 */
trait ResolvesBusiness
{
    protected function business(Request $request): Business
    {
        $business = $request->user()->business();

        if ($business === null) {
            throw new HttpResponseException(
                ApiResponse::error('No business found for this account.', status: 404),
            );
        }

        return $business;
    }
}
