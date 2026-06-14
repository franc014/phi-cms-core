<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Section extends ContentModel
{
    use HasFactory;

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
    }
}
