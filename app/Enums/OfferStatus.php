<?php

namespace App\Enums;

/**
 * Stored base status of an offer. The *effective* status shown to users
 * (scheduled / expired / sold_out) is derived from dates + remaining quantity
 * on the model — only Active and Archived are persisted here
 * (document/phase/07 §Offer Management — create/edit/delete/archive).
 */
enum OfferStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
