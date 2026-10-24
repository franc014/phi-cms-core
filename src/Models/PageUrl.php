<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageUrl extends Model
{
    protected $table = 'page_urls';

    protected $fillable = [
        'slug',
        'page_id',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'page_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
