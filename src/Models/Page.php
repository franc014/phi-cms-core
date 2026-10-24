<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Pages\PageMutation;
use JFA\PhiCmsCore\Sections\SectionConflict;

/**
 * @property PageStatus $status
 * @property Carbon|null $published_at
 */
class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'route',
        'published_at',
        'template',
        'draft_fields',
        'page_version',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists && ! PageMutation::allowed() && ($this->getOriginal('page_version') !== null || $this->getOriginal('draft_fields') !== null) && $this->isDirty([
            'title',
            'slug',
            'description',
            'status',
            'route',
            'published_at',
            'template',
            'draft_fields',
            'page_version',
        ])) {
            throw ValidationException::withMessages(['page' => 'Adopted Pages must be changed through the Page draft workflow.']);
        }

        return $this->withSectionLocks(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        if ($this->exists && ! PageMutation::allowed() && ($this->getOriginal('page_version') !== null || $this->getOriginal('draft_fields') !== null)) {
            throw ValidationException::withMessages(['page' => 'Adopted Pages must be deleted through the Page workflow.']);
        }

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
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if (! PageMutation::allowed() && ($current->page_version !== $this->getOriginal('page_version') || ($current->draft_fields !== null && $this->getOriginal('draft_fields') === null))) {
                throw ValidationException::withMessages(['page' => 'Page ownership changed. Reload latest; retained draft and URL recovery guards must not be bypassed.']);
            }
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
            'draft_fields' => 'array',
            'page_version' => 'integer',
        ];
    }

    public function publish(): void
    {
        if ($this->page_version !== null) {
            throw ValidationException::withMessages(['page' => 'Adopted Pages must be published through the reviewed Page workflow.']);
        }

        $this->status = PageStatus::PUBLISHED;
        $this->published_at = now();
        $this->save();
    }

    public function unpublish(): void
    {
        if ($this->page_version !== null) {
            throw ValidationException::withMessages(['page' => 'Adopted Pages must be unpublished through the Page workflow.']);
        }

        $this->status = PageStatus::DRAFT;
        $this->published_at = null;
        $this->save();
    }

    /** @return BelongsToMany<Section, $this, PageSection> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)
            ->using(PageSection::class)
            ->wherePivot('stage', 'published')
            ->withPivot(['id', 'order_column', 'stage', 'occurrence_id', 'slot'])
            ->orderByPivot('order_column')
            ->orderByPivot('id');
    }

    /** @return BelongsToMany<Section, $this, PageSection> */
    public function draftSections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class)
            ->using(PageSection::class)
            ->wherePivot('stage', 'draft')
            ->withPivot(['id', 'order_column', 'stage', 'occurrence_id', 'slot'])
            ->orderByPivot('order_column')
            ->orderByPivot('id');
    }

    /** @return HasMany<PageSection, $this> */
    public function draftSectionAssignments(): HasMany
    {
        return $this->hasMany(PageSection::class)
            ->where('stage', 'draft')
            ->with('section')
            ->orderBy('order_column')
            ->orderBy('id');
    }

    /** @return HasMany<PageUrl, $this> */
    public function urls(): HasMany
    {
        return $this->hasMany(PageUrl::class);
    }

    public function metatags(): MorphOne
    {
        return $this->morphOne(Metatags::class, 'metatable');
    }

    public function hasSections(): bool
    {
        return $this->sections()->count() > 0;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PageStatus::PUBLISHED);
    }

    public static function bySlug(string $slug): self
    {
        return self::query()
            ->where('slug', $slug)
            ->published()
            ->with(['metatags', 'sections'])
            ->firstOrFail();
    }
}
