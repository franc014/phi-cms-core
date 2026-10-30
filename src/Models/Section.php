<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string $slug
 * @property SectionStatus $status
 * @property array<string, mixed>|null $draft_content
 * @property array<string, mixed>|null $published_content
 * @property int|null $content_version
 * @property list<mixed>|null $legacy_image_sources
 * @property array<array-key, mixed>|null $content
 */
class Section extends ContentModel
{
    use HasFactory;
    use LogsActivity;

    protected $hidden = ['draft_content', 'published_content'];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if ($this->type === 'services' && (! $this->exists || $this->isDirty('type')) && DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE sections IN SHARE ROW EXCLUSIVE MODE');
            }
            if ($this->exists) {
                $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
                if ($current->content_version !== null || ($current->type === 'gallery' && $current->published_content !== null) || $this->isDirty(['draft_content', 'published_content', 'content_version'])) {
                    throw ValidationException::withMessages(['content' => __('phi-cms-core::phi-cms-core.adopted_section_editor')]);
                }
                if ($current->type !== 'services' && $this->type === 'services' && $this->servicesHaveBeenAdopted()) {
                    throw ValidationException::withMessages(['content' => __('phi-cms-core::phi-cms-core.legacy_services_conversion')]);
                }
            } elseif ($this->draft_content !== null || $this->published_content !== null || $this->content_version !== null
                || ($this->type === 'services' && $this->servicesHaveBeenAdopted())) {
                throw ValidationException::withMessages(['content' => __('phi-cms-core::phi-cms-core.legacy_services_generator')]);
            }

            return parent::save($options);
        });
    }

    private function servicesHaveBeenAdopted(): bool
    {
        return Schema::hasColumn($this->getTable(), 'content_version')
            && static::query()->where('type', 'services')->whereNotNull('published_content')->exists();
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            // Adoption and conversion guards lock Media before Sections. Deleting
            // cascades into Media, so take that same order before locking the row.
            if ($this->exists) {
                $mediaClass = config('media-library.media_model', Media::class);
                $ownedMedia = $mediaClass::query()
                    ->where('model_type', $this->getMorphClass())
                    ->where('model_id', $this->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($ownedMedia as $media) {
                    if (LegacySectionGuard::hasProtectedGalleryUsage($media)
                        || $media->collection_name === config('phi-cms.images.collection', 'phi-section-images')) {
                        throw ValidationException::withMessages(['content' => 'This Section owns retained images or supplies protected Gallery bytes.']);
                    }
                }
            }

            $current = static::query()->lockForUpdate()->find($this->getKey());
            if ($current === null) {
                return null;
            }
            $usages = DB::table('page_section')
                ->join('pages', 'pages.id', '=', 'page_section.page_id')
                ->where('page_section.section_id', $current->id)
                ->orderBy('pages.id')
                ->orderBy('page_section.stage')
                ->orderBy('page_section.order_column')
                ->pluck('pages.title')
                ->unique()
                ->implode(', ');
            if ($usages !== '') {
                throw ValidationException::withMessages(['content' => __('phi-cms-core::phi-cms-core.recovery_protected_section_delete', [
                    'usages' => $usages ?: __('phi-cms-core::phi-cms-core.none'),
                ])]);
            }

            DB::table('page_legacy_occurrences')->where('section_id', $current->getKey())->delete();

            return parent::delete();
        });
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => 'string',
            'draft_content' => 'array',
            'published_content' => 'array',
            'content_version' => 'integer',
            'legacy_image_sources' => 'array',
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['content'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsToMany<Page, $this, PageSection> */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class)
            ->using(PageSection::class)
            ->wherePivot('stage', 'published')
            ->withPivot(['id', 'order_column', 'stage', 'occurrence_id', 'slot'])
            ->orderByPivot('order_column')
            ->orderByPivot('id');
    }

    /** @return BelongsToMany<Page, $this, PageSection> */
    public function draftPages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class)
            ->using(PageSection::class)
            ->wherePivot('stage', 'draft')
            ->withPivot(['id', 'order_column', 'stage', 'occurrence_id', 'slot'])
            ->orderByPivot('order_column')
            ->orderByPivot('id');
    }
}
