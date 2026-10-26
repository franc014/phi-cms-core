<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Support\Facades\Gate;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;

final class SectionImageContext
{
    private ?int $sectionId = null;

    private ?int $pageId = null;

    private bool $preview = false;

    private bool $draftAuthorized = false;

    private bool $readAuthorized = false;

    public function set(Section $section, ?Page $page = null, bool $preview = true): void
    {
        $this->sectionId = (int) $section->getKey();
        $this->pageId = $page?->getKey();
        $this->preview = $preview;
        $this->draftAuthorized = false;
        $this->readAuthorized = false;
    }

    public function clear(): void
    {
        $this->sectionId = null;
        $this->pageId = null;
        $this->preview = false;
        $this->draftAuthorized = false;
        $this->readAuthorized = false;
    }

    public function section(): Section
    {
        abort_if($this->sectionId === null, 403);

        return Section::query()->findOrFail($this->sectionId);
    }

    public function page(): ?Page
    {
        return $this->pageId === null ? null : Page::query()->findOrFail($this->pageId);
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function authorizeRead(): Section
    {
        $section = $this->section();
        $actor = auth()->user()?->fresh();

        abort_if($actor === null, 403);
        $this->draftAuthorized = Gate::forUser($actor)->allows('viewDraft', $section);

        if ($this->pageId !== null) {
            $page = $this->page();
            abort_if($page === null, 403);
            Gate::forUser($actor)->authorize('preview', $page);
            $relationship = $this->preview && $page->page_version !== null
                ? $page->draftSections()
                : $page->sections();
            abort_unless($relationship->whereKey($section->getKey())->exists(), 403);
        } else {
            abort_unless($this->draftAuthorized, 403);
        }

        $this->readAuthorized = true;

        return $section;
    }

    public function canReadDraft(): bool
    {
        return $this->draftAuthorized;
    }

    public function hasAuthorizedRead(): bool
    {
        return $this->readAuthorized;
    }

    public function authorizeUpload(): Section
    {
        $section = $this->authorizeRead();
        abort_unless($this->draftAuthorized, 403);
        abort_unless($section->content_version !== null, 403);
        $actor = auth()->user()?->fresh();

        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize('update', $section);

        return $section;
    }
}
