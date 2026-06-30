<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Metatags extends Model
{
    protected $fillable = [
        'title',
        'description',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_card',
        'twitter_description',
        'twitter_image',
        'robots',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function metatable(): MorphTo
    {
        return $this->morphTo();
    }
}
