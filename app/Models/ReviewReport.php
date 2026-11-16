<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's flag on someone else's review. The admin acts on it from the
 * Reviews page (hide or keep).
 */
class ReviewReport extends Model
{
    /** Reasons a customer can pick in the app. */
    public const REASONS = ['spam', 'offensive', 'fake', 'personal_info', 'other'];

    protected $fillable = ['review_id', 'reporter_id', 'reason', 'note'];

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
