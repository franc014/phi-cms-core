<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Support\Facades\Gate;
use JFA\PhiCmsCore\Models\Page;

final class PageSeoImageContext
{
    private ?int $pageId = null;

    private bool $preview = false;

    private bool $readAuthorized = false;

    private ?string $editor = null;

    private ?string $token = null;

    public function set(Page $page, bool $preview = true, ?string $editor = null, ?string $token = null): void
    {
        $this->pageId = (int) $page->getKey();
        $this->preview = $preview;
        $this->readAuthorized = false;
        $this->editor = $editor;
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->pageId = null;
        $this->preview = false;
        $this->readAuthorized = false;
        $this->editor = null;
        $this->token = null;
    }

    /** @return array{editor: string|null, token: string|null} */
    public function editor(): array
    {
        return ['editor' => $this->editor, 'token' => $this->token];
    }

    /** @param array<string, mixed> $binding */
    public function matchesEditor(array $binding): bool
    {
        return ($binding['editor'] ?? null) === $this->editor
            && ($binding['token'] ?? null) === $this->token
            && ($this->editor === null || (
                is_string($this->token)
                && session('phi-cms.page-upload-editors.' . $this->editor) === $this->token
                && session('phi-cms.page-active-editor.' . $this->pageId) === $this->editor
            ));
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
