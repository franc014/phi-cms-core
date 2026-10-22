<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Validation\ValidationException;
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

    public static function registerConversions(Section $section, ?Media $media = null): void
    {
        if ($media !== null) {
            self::conversion($media);

            if ($media->exists || $media->model !== null) {
                return;
            }
        }

        self::assertConversionsAllowed($section);
    }

    public static function conversion(Media $media, bool $lockOwner = false): void
    {
        $current = $media->exists
            ? $media->newQuery()->with('model')->find($media->getKey())
            : $media;

        if ($current?->model instanceof Section) {
            $owner = $lockOwner
                ? Section::query()->lockForUpdate()->find($current->model->getKey())
                : $current->model;

            if ($owner !== null) {
                self::assertConversionsAllowed($owner);
            }
        }
    }

    public static function media(Media $media): void
    {
        $owners = [[$media->model_type, $media->model_id], [$media->getRawOriginal('model_type'), $media->getRawOriginal('model_id')]];
        foreach ($owners as [$type, $id]) {
            $section = self::sectionForOwner($type, $id);
            if ($section !== null) {
                self::assertMediaMutationAllowed($section);
            }
        }
    }

    private static function assertConversionsAllowed(Section $section): void
    {
        $current = $section->exists ? $section->fresh() : $section;

        if ($current?->content_version !== null || $current?->draft_content !== null || $current?->published_content !== null) {
            throw ValidationException::withMessages(['media' => 'Adopted and recovery-protected Section conversions cannot be regenerated through legacy paths.']);
        }
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
