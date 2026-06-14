<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use JFA\PhiCmsCore\CMS\ContentResolver;
use JFA\PhiCmsCore\Enums\PageStatus;

class Page extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'published_at' => 'datetime',
            'status' => PageStatus::class,
            'metatags' => 'array',
        ];
    }

    public function publish(): void
    {
        $this->status = PageStatus::PUBLISHED;
        $this->published_at = now();
        $this->save();
    }

    public function unpublish(): void
    {
        $this->status = PageStatus::DRAFT;
        $this->published_at = null;
        $this->save();
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)
            ->using(PageSection::class)
            ->withPivot('order_column');
    }

    public function hasSections(): bool
    {
        return $this->sections()->count() > 0;
    }

    public function scopePublished($query)
    {
        return $query->where('status', PageStatus::PUBLISHED);
    }

    public static function bySlug($slug)
    {
        $cacheKey = 'page-id-' . $slug;

        $pageId = Cache::remember($cacheKey, now()->addDay(), function () use ($slug) {
            $page = self::where('slug', $slug)->published()
                ->firstOrFail();

            return $page->id;
        });

        return self::where('id', $pageId)
            ->with('sections', function ($query) {
                $query->orderBy('order_column');
            })
            ->firstOrFail();
    }

    public function sectionsForUI(): ?array
    {
        $sectionsKeyed = $this->sections->keyBy('slug');

        $sectionsMapped = $sectionsKeyed->map(function ($section) {
            $contentResolver = new ContentResolver($section, pageId: $this->id);

            return [
                'title' => $section->title,
                'slug' => $section->slug,
                'content' => $contentResolver->resolve(),
            ];
        });

        return $sectionsMapped->all();
    }
}
