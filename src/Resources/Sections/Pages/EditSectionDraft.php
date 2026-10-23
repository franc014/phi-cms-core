<?php

namespace JFA\PhiCmsCore\Resources\Sections\Pages;

use Filament\Resources\Pages\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;
use JFA\PhiCmsCore\Sections\SectionContent;
use JFA\PhiCmsCore\Sections\SectionImageContext;
use Livewire\Attributes\Locked;

class EditSectionDraft extends Page
{
    protected static string $resource = SectionResource::class;

    protected string $view = 'phi-cms-core::resources.sections.draft';

    #[Locked]
    public int $recordId;

    public function mount(int | string $record): void
    {
        $this->recordId = (int) $record;
        $section = Section::findOrFail($this->recordId);
        app(SectionContent::class)->draft($section);
        app(SectionImageContext::class)->set($section, preview: true);
    }

    public function hydrate(): void
    {
        $section = Section::findOrFail($this->recordId);
        app(SectionContent::class)->draft($section);
        app(SectionImageContext::class)->set($section, preview: true);
    }
}
