<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use JFA\PhiCmsCore\Pages\PageMetatagsGuard;

class Metatags extends Model
{
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            PageMetatagsGuard::check($this);

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            PageMetatagsGuard::check($this);

            return parent::delete();
        });
    }

    public function newEloquentBuilder(mixed $query): MetatagsBuilder
    {
        return new MetatagsBuilder($query);
    }

    protected $fillable = [
        'metatable_type',
        'metatable_id',
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
