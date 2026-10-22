<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Section;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class LegacySectionGuard
{
    public static function edit(Section $record): void
    {
        if ($record->fresh()?->content_version !== null) {
            throw ValidationException::withMessages(['content' => 'Use the Services draft editor. Legacy content, metadata, status and media mutations are disabled.']);
        }
    }

    public static function media(Media $media): void
    {
        $owners = [[$media->model_type, $media->model_id], [$media->getRawOriginal('model_type'), $media->getRawOriginal('model_id')]];
        foreach ($owners as [$type, $id]) {
            if ($type === (new Section)->getMorphClass() && $id !== null) {
                $section = Section::find((int) $id);
                if ($section !== null) {
                    if ($section->draft_content !== null || $section->published_content !== null) {
                        throw ValidationException::withMessages(['media' => 'Section media is adoption/recovery-protected. Legacy media mutations are disabled.']);
                    }
                    self::edit($section);
                }
            }
        }
    }
}
