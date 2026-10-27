<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Pages\PageMutation;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * @property int $id
 * @property int $page_id
 * @property int $section_id
 * @property string $stage
 * @property string|null $occurrence_id
 * @property string|null $slot
 */
class PageSection extends Pivot implements Sortable
{
    use SortableTrait;

    protected $table = 'page_section';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'page_id',
        'section_id',
        'order_column',
        'stage',
        'occurrence_id',
        'slot',
    ];

    public $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if (! PageMutation::allowed()) {
                $this->guardAdoptedPlacement();
            }

            return parent::save($options);
        });
    }

    public function delete(): int
    {
        return DB::transaction(function (): int {
            if (! PageMutation::allowed()) {
                $this->guardAdoptedPlacement();
            }

            return parent::delete();
        });
    }

    private function guardAdoptedPlacement(): void
    {
        $sectionIds = array_filter([$this->section_id, $this->getOriginal('section_id')]);
        $pageIds = array_filter([$this->getAttribute('page_id'), $this->getOriginal('page_id')]);
        $sectionIds = array_merge($sectionIds, DB::table('page_section')->whereIn('page_id', $pageIds)->pluck('section_id')->all());
        $sections = Section::query()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
        $pages = Page::query()->whereIn('id', $pageIds)->orderBy('id')->lockForUpdate()->get();
        if ($pages->contains(fn (Page $page): bool => $page->page_version !== null || $page->draft_fields !== null)
            || $sections->contains(fn (Section $section): bool => $section->content_version !== null)) {
            throw ValidationException::withMessages(['sections' => __('phi-cms-core::phi-cms-core.adopted_section_placements_read_only')]);
        }
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'page_id' => 'integer',
            'section_id' => 'integer',
            'order_column' => 'integer',
            'slot' => 'string',
        ];
    }
}
