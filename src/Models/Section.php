<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $type
 * @property string $title
 * @property string $slug
 * @property array<string, mixed>|null $draft_content
 * @property array<string, mixed>|null $published_content
 * @property int|null $content_version
 */
class Section extends ContentModel
{
    use HasFactory;
    use LogsActivity;

    public bool $registerMediaConversionsUsingModelInstance = true;

    protected $hidden = ['draft_content', 'published_content'];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if ($this->type === 'services' && (! $this->exists || $this->isDirty('type')) && DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE sections IN SHARE ROW EXCLUSIVE MODE');
            }
            if ($this->exists) {
                $current = static::query()->lockForUpdate()->findOrFail($this->getKey());
                if ($current->content_version !== null || $this->isDirty(['draft_content', 'published_content', 'content_version'])) {
                    throw ValidationException::withMessages(['content' => 'Adopted Sections must use the definition-backed draft editor. Legacy metadata, status and media edits are disabled.']);
                }
                if ($current->type !== 'services' && $this->type === 'services' && $this->servicesHaveBeenAdopted()) {
                    throw ValidationException::withMessages(['content' => 'Changing legacy content into Services is unsupported after adoption. Use the definition-backed workflow.']);
                }
            } elseif ($this->draft_content !== null || $this->published_content !== null || $this->content_version !== null
                || ($this->type === 'services' && $this->servicesHaveBeenAdopted())) {
                throw ValidationException::withMessages(['content' => 'Creating or replicating Services through legacy generators is unsupported after adoption.']);
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
            $current = static::query()->lockForUpdate()->find($this->getKey());
            if ($current === null) {
                return null;
            }
            if ($current->content_version !== null || $current->draft_content !== null || $current->published_content !== null) {
                $usages = $current->pages()->orderBy('pages.id')->pluck('pages.title')->implode(', ');

                throw ValidationException::withMessages(['content' => 'Section is recovery-protected and cannot be deleted. Page usages: ' . ($usages ?: '(none)') . '.']);
            }

            return parent::delete();
        });
    }

    public function registerAllMediaConversions(?Media $media = null): void
    {
        LegacySectionGuard::registerConversions($this, $media);

        parent::registerAllMediaConversions($media);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => 'string',
            'draft_content' => 'array',
            'published_content' => 'array',
            'content_version' => 'integer',
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
        return $this->belongsToMany(Page::class)->using(PageSection::class)->withPivot('order_column');
    }
}
