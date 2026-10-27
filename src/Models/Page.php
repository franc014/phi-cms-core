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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Pages\PageMutation;
use JFA\PhiCmsCore\Sections\SectionConflict;

/**
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property int|null $page_version
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
            if (! PageMutation::allowed() && $current->page_version === null && $current->draft_fields === null && $current->getRawOriginal('status') === PageStatus::PUBLISHED->value && $this->isDirty('slug')) {
                throw ValidationException::withMessages(['page' => 'Published legacy Page slugs must be changed through the URL-preserving Page workflow.']);
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

        $this->mutateLegacyAvailability(PageStatus::PUBLISHED);
    }

    public function unpublish(): void
    {
        if ($this->page_version !== null) {
            throw ValidationException::withMessages(['page' => 'Adopted Pages must be unpublished through the Page workflow.']);
        }

        $this->mutateLegacyAvailability(PageStatus::DRAFT);
    }

    private function mutateLegacyAvailability(PageStatus $status): void
    {
        DB::transaction(function () use ($status): void {
            $snapshot = static::query()->findOrFail($this->getKey());
            $usesUrlIndex = Schema::hasTable('page_urls');
            $claims = $usesUrlIndex
                ? PageUrl::query()->where(function (Builder $query) use ($snapshot): void {
                    $query->where('page_id', $snapshot->getKey())->orWhere('slug', $snapshot->slug);
                })->orderBy('slug')->lockForUpdate()->get()
                : collect();
            $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
            if ($current->page_version !== null) {
                throw ValidationException::withMessages(['page' => 'Adopted Pages must use the reviewed Page workflow.']);
            }
            if ($snapshot->slug !== $current->slug) {
                throw ValidationException::withMessages(['page' => 'Page ownership changed. Reload latest before changing availability.']);
            }

            if ($status === PageStatus::PUBLISHED && $usesUrlIndex) {
                $claim = $claims->first(fn (PageUrl $url): bool => $url->slug === $current->slug);
                if ($claim !== null && (int) $claim->page_id !== (int) $current->getKey() && $claim->kind === 'current') {
                    throw ValidationException::withMessages(['page' => "The slug [{$current->slug}] is already the current URL of another Page."]);
                }
                $ownedCurrent = $claims->first(fn (PageUrl $url): bool => (int) $url->page_id === (int) $current->getKey() && $url->kind === 'current');
                if ($ownedCurrent !== null && $ownedCurrent->slug !== $current->slug) {
                    throw ValidationException::withMessages(['page' => 'The Page has an inconsistent current URL claim; reconcile it before publishing.']);
                }
                if ($claim !== null && (int) $claim->page_id !== (int) $current->getKey()) {
                    $claim->delete();
                }
            }

            DB::table('pages')->where('id', $current->getKey())->update([
                'status' => $status->value,
                'published_at' => $status === PageStatus::PUBLISHED ? now() : null,
                'updated_at' => now(),
            ]);
            if ($usesUrlIndex) {
                if ($status === PageStatus::PUBLISHED) {
                    PageUrl::query()->updateOrCreate(['slug' => $current->slug], ['page_id' => $current->getKey(), 'kind' => 'current']);
                } else {
                    PageUrl::query()->where('page_id', $current->getKey())->delete();
                }
            }
        });
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
