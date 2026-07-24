<?php

namespace App\Services\Import;

use App\Enums\ImportSource;
use App\Exceptions\ImportException;
use App\Support\Import\ImportedBusinessData;

/**
 * SPEC-011 §FUTURE READY — the single contract every importer implements
 * (Google today; Facebook/Instagram/Justdial/IndiaMART/Website later). The
 * BusinessImportService only ever talks to this interface.
 */
interface ImportProvider
{
    /** The source this provider handles. */
    public function source(): ImportSource;

    /** Whether this provider recognizes the given URL. */
    public function supports(string $url): bool;

    /**
     * Fetch public business information for the URL and return the normalized,
     * unsaved preview payload.
     *
     * @throws ImportException on invalid URL, not-found, upstream or timeout errors
     */
    public function fetch(string $url): ImportedBusinessData;
}
