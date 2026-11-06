<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Support\Facades\Gate;
use JFA\PhiCmsCore\Models\Page;

final class PageSeoImageContext
{
    private ?int $pageId = null;

    private bool $preview = false;

    private bool $readAuthorized = false;

    public function set(Page $page, bool $preview = true): void
    {
        $this->pageId = (int) $page->getKey();
        $this->preview = $preview;
        $this->readAuthorized = false;
    }

    public function clear(): void
    {
        $this->pageId = null;
        $this->preview = false;
        $this->readAuthorized = false;
    }

    public function page(): Page
    {
        abort_if($this->pageId === null, 403);

        return Page::query()->findOrFail($this->pageId);
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function authorizeRead(): Page
    {
        $page = Page::query()->with('metatags')->findOrFail($this->page()->getKey());
        $actor = auth()->user()?->fresh();

        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize('preview', $page);

        if (! $this->preview) {
            abort_unless($page->status?->value === 'published', 404);
        }

        $this->readAuthorized = true;

        return $page;
    }

    public function hasAuthorizedRead(): bool
    {
        return $this->readAuthorized;
    }

    public function authorizeUpload(): Page
    {
        $page = $this->authorizeRead();
        abort_unless($page->page_version !== null, 403);

        $actor = auth()->user()?->fresh();
        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize('update', $page);

        return $page;
    }
}
