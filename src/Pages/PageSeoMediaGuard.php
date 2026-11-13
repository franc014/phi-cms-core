<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Sections\MediaMutation;

final class PageSeoMediaGuard
{
    public static function media(Media $media): void
    {
        if (MediaMutation::allowed()) {
            return;
        }

        $owners = array_unique([
            [$media->model_type, $media->model_id, $media->collection_name],
            [$media->getRawOriginal('model_type'), $media->getRawOriginal('model_id'), $media->getRawOriginal('collection_name')],
        ], SORT_REGULAR);

        foreach ($owners as [$type, $id, $collection]) {
            if ($type !== (new Page)->getMorphClass() || $id === null || $collection !== app(PageSeoImages::class)->collectionName()) {
                continue;
            }

            throw ValidationException::withMessages(['media' => 'Page SEO images are immutable and may only be changed through the Page workflow.']);
        }
    }
}
