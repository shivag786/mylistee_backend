<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A CMS page — About / Privacy / Terms / FAQ / … (document/phase/14 §CMS Management).
 */
class CmsPage extends Model
{
    protected $fillable = ['slug', 'title', 'body', 'status', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(function (CmsPage $page): void {
            if (empty($page->uuid)) {
                $page->uuid = (string) Str::uuid();
            }
        });
    }
}
