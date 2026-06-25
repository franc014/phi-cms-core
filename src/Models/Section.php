<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Section extends ContentModel
{
    use HasFactory;
    use LogsActivity;

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => 'string',
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['content'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class);
    }
}
