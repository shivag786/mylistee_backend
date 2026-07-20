<?php

namespace App\Enums;

/**
 * The three platform roles (document/phase/02 §User Roles).
 * Stored as a string on the users table.
 */
enum UserRole: string
{
    case Customer = 'customer';
    case BusinessOwner = 'business_owner';
    case Admin = 'admin';

    /** Default role assigned to a newly registered (Google) user. */
    public static function default(): self
    {
        return self::Customer;
    }
}
