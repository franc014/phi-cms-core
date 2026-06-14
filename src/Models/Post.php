<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Tags\HasTags;

class Post extends Model
{
    use HasTags;

    protected function casts(): array
    {
        return [
            'metatags' => 'array',
            'content' => 'array',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }

    public function publish()
    {
        $this->published_at = now();
        $this->save();
    }

    public function unpublish()
    {
        $this->published_at = null;
        $this->save();
    }
}
