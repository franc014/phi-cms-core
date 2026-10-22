<?php

namespace JFA\PhiCmsCore\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/** @property int $section_id */
class PageSection extends Pivot implements Sortable
{
    use SortableTrait;

    public $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            $this->guardAdoptedPlacement();

            return parent::save($options);
        });
    }

    public function delete(): int
    {
        return DB::transaction(function (): int {
            $this->guardAdoptedPlacement();

            return parent::delete();
        });
    }

    private function guardAdoptedPlacement(): void
    {
        $sectionIds = array_filter([$this->section_id, $this->getOriginal('section_id')]);
        $pageIds = array_filter([$this->getAttribute('page_id'), $this->getOriginal('page_id')]);
        $sectionIds = array_merge($sectionIds, DB::table('page_section')->whereIn('page_id', $pageIds)->pluck('section_id')->all());
        $sections = Section::query()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
        if ($sections->contains(fn (Section $section): bool => $section->content_version !== null)) {
            throw ValidationException::withMessages(['sections' => 'Adopted Section placements are read-only until the Page composition workflow is installed.']);
        }
    }
}
