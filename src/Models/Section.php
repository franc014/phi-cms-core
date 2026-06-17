<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Section extends ContentModel
{
    use HasFactory;

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => 'string',
        ]);
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
    }
}
