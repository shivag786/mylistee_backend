<?php

namespace App\Enums;

/**
 * Invoice payment state (document/phase/14 §Payment Management). v1 has no
 * gateway, so an upgrade writes a Paid invoice directly; the other states exist
 * for when a real gateway is wired in.
 */
enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
