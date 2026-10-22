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

        if ($current?->content_version !== null) {
            throw ValidationException::withMessages(['content' => 'Use the Services draft editor. Legacy content, metadata, status and media mutations are disabled.']);
        }
    }

    public static function assertLegacyPlacementAllowed(Page $page): void
    {
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

        if ($sections->contains(fn (Section $section): bool => $section->content_version !== null)) {
            throw ValidationException::withMessages(['sections' => 'Adopted Section placements are read-only until the Page composition workflow is installed.']);
        }
    }

    public static function conversion(Media $media): void
    {
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
            throw ValidationException::withMessages(['media' => 'Adopted and recovery-protected Section conversions cannot be regenerated through legacy paths.']);
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
            throw ValidationException::withMessages(['media' => 'Section media is adoption/recovery-protected. Legacy media mutations are disabled.']);
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
