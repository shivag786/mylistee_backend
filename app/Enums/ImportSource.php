<?php

namespace App\Enums;

/**
 * SPEC-011 §FUTURE READY — every importer shares one architecture. Google is the
 * only provider wired up today; the rest are declared so the contract, logs and
 * UI are ready to accept them without a schema change.
 */
enum ImportSource: string
{
    case Google = 'google';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Justdial = 'justdial';
    case IndiaMart = 'indiamart';
    case Website = 'website';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google Business Profile',
            self::Facebook => 'Facebook Page',
            self::Instagram => 'Instagram Business',
            self::Justdial => 'Justdial',
            self::IndiaMart => 'IndiaMART',
            self::Website => 'Website Metadata',
        };
    }
}
