<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use JFA\PhiCmsCore\Enums\SectionStatus;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ContentModel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'content' => 'array',
            'status' => SectionStatus::class,
        ];
    }

    public function activate(): void
    {
        $this->status = SectionStatus::ACTIVE;
        $this->save();
    }

    public function deactivate(): void
    {
        $this->status = SectionStatus::INACTIVE;
        $this->save();
    }

    public function registerAllMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('thumb')
            ->fit(Fit::Contain, 300, 300);
    }

    public function getImages(): ?MediaCollection
    {
        return $this->hasMedia('*') ? $this->getMedia('*') : null;
    }
}
