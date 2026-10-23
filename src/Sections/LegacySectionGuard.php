<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class LegacySectionGuard
{
    public static function edit(Section $record): void
    {
        $current = $record->exists ? $record->fresh() : $record;

        if ($current?->content_version !== null || ($current?->type === 'gallery' && $current->published_content !== null)) {
            throw ValidationException::withMessages(['content' => __('phi-cms-core::phi-cms-core.services_draft_editor_required')]);
        }
    }

    public static function assertLegacyPlacementAllowed(Page $page): void
    {
        // Serialize related creates against each other and Services table locks
        // before taking rows, without blocking ordinary row owners' UPDATEs.
        // A Services insert can then safely upgrade its table lock after this guard.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE sections IN SHARE UPDATE EXCLUSIVE MODE');
        }

        $sectionIds = DB::table('page_section')
            ->where('page_id', $page->getKey())
            ->orderBy('section_id')
            ->pluck('section_id')
            ->all();
        $sections = Section::query()
            ->whereIn('id', $sectionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $currentSectionIds = DB::table('page_section')
            ->where('page_id', $page->getKey())
            ->orderBy('section_id')
            ->pluck('section_id')
            ->all();

        if ($sectionIds !== $currentSectionIds) {
            throw ValidationException::withMessages(['sections' => __('phi-cms-core::phi-cms-core.section_placements_changed')]);
        }

        if ($sections->contains(fn (Section $section): bool => $section->content_version !== null)) {
            throw ValidationException::withMessages(['sections' => __('phi-cms-core::phi-cms-core.adopted_section_placements_read_only')]);
        }
    }

    public static function conversion(Media $media): void
    {
        if (MediaMutation::allowed()) {
            return;
        }

        // Adoption locks Media before Sections. Take the same order here so a
        // queued conversion cannot deadlock with an in-flight adoption.
        $current = $media->exists
            ? $media->newQuery()->with('model')->lockForUpdate()->find($media->getKey())
            : $media;

        if (! $current?->model instanceof Section) {
            return;
        }

        $owner = Section::query()->lockForUpdate()->find($current->model->getKey());

        if ($owner !== null) {
            self::assertConversionsAllowed($owner);
        }
    }

    public static function media(Media $media): void
    {
        if (MediaMutation::allowed()) {
            return;
        }

        $owners = array_unique([
            [$media->model_type, $media->model_id],
            [$media->getRawOriginal('model_type'), $media->getRawOriginal('model_id')],
        ], SORT_REGULAR);

        foreach ($owners as [$type, $id]) {
            $section = self::sectionForOwner($type, $id);
            if ($section !== null) {
                self::assertMediaMutationAllowed($section);
            }
        }
    }

    public static function conversionIsProtected(Media $media): bool
    {
        $current = $media->exists
            ? $media->newQuery()->with('model')->find($media->getKey())
            : $media;

        $owner = $current?->model;

        if (! $owner instanceof Section) {
            return false;
        }

        return self::isProtected($owner->exists ? $owner->fresh() : $owner);
    }

    private static function assertConversionsAllowed(Section $section): void
    {
        $current = $section->exists ? $section->fresh() : $section;

        if (self::isProtected($current)) {
            throw ValidationException::withMessages(['media' => __('phi-cms-core::phi-cms-core.protected_section_conversions')]);
        }
    }

    private static function isProtected(?Section $section): bool
    {
        return $section !== null
            && ($section->content_version !== null || $section->draft_content !== null || $section->published_content !== null);
    }

    private static function assertMediaMutationAllowed(Section $section): void
    {
        $current = $section->exists ? $section->fresh() : $section;

        if ($current?->draft_content !== null || $current?->published_content !== null) {
            throw ValidationException::withMessages(['media' => __('phi-cms-core::phi-cms-core.protected_section_media')]);
        }

        self::edit($current);
    }

    private static function sectionForOwner(mixed $type, mixed $id): ?Section
    {
        if ($type !== (new Section)->getMorphClass() || $id === null) {
            return null;
        }

        return Section::query()->find((int) $id);
    }
}
