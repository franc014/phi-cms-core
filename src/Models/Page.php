<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Sections\SectionConflict;

/**
 * @property PageStatus $status
 * @property Carbon|null $published_at
 */
class Page extends Model
{
    use HasFactory;

    public function save(array $options = []): bool
    {
        return $this->withSectionLocks(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->withSectionLocks(fn (): ?bool => parent::delete());
    }

    private function withSectionLocks(\Closure $operation): mixed
    {
        if (! $this->exists) {
            return $operation();
        }

        return DB::transaction(function () use ($operation): mixed {
            $sectionIds = DB::table('page_section')->where('page_id', $this->getKey())->orderBy('section_id')->pluck('section_id')->unique()->values()->all();
            Section::query()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
            static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $currentIds = DB::table('page_section')->where('page_id', $this->getKey())->orderBy('section_id')->pluck('section_id')->unique()->values()->all();
            if ($currentIds !== $sectionIds) {
                throw new SectionConflict;
            }

            return $operation();
        });
    }

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'published_at' => 'datetime',
            'status' => PageStatus::class,
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

    /** @return BelongsToMany<Section, $this, PageSection> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)
            ->using(PageSection::class)
            ->withPivot('order_column');
    }

    public function metatags(): MorphOne
    {
        return $this->morphOne(Metatags::class, 'metatable');
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
}
