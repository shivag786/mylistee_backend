<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A star rating for one product or combo inside a review. */
class ReviewItem extends Model
{
    protected $fillable = [
        'review_id',
        'product_id',
        'combo_id',
        'item_type',
        'name',
        'rating',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
