<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Metatags;
use JFA\PhiCmsCore\Models\Page;

final class PageMetatagsGuard
{
    public static function check(Metatags $metatags): void
    {
        if (PageMutation::allowed()) {
            return;
        }

        $pageType = (new Page)->getMorphClass();
        $owners = [
            [$metatags->metatable_type, $metatags->metatable_id],
            [$metatags->getRawOriginal('metatable_type'), $metatags->getRawOriginal('metatable_id')],
        ];
        $ids = [];
        foreach ($owners as [$type, $id]) {
            if ($type === $pageType && $id !== null) {
                $ids[] = $id;
            }
        }
        $references = [];
        foreach (['og_image', 'twitter_image'] as $key) {
            foreach ([$metatags->getAttribute($key), $metatags->getRawOriginal($key)] as $reference) {
                if (is_string($reference) && str_starts_with($reference, 'media:')) {
                    throw ValidationException::withMessages(['metatags' => 'Protected image references must be changed through the Page draft workflow.']);
                }
                $references[] = $reference;
            }
        }
        app(PageSeoImages::class)->assertLegacyMetatagReferences($references);

        foreach ($ids === [] ? [] : Page::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $page) {
            if ($page->page_version !== null || $page->draft_fields !== null) {
                throw ValidationException::withMessages(['metatags' => 'Managed Page metadata must be changed through the Page draft workflow.']);
            }
        }

    }
}
