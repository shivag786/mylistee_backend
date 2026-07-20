<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Favorite;
use App\Models\User;

/** Customer favorites (document/phase/02 §Favorites). */
class FavoriteService
{
    /** Add a favorite (idempotent). */
    public function add(User $user, Business $business): void
    {
        Favorite::firstOrCreate([
            'customer_id' => $user->id,
            'business_id' => $business->id,
        ]);
    }

    public function remove(User $user, Business $business): void
    {
        $user->favorites()->where('business_id', $business->id)->delete();
    }
}
