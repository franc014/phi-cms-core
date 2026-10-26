<?php

namespace JFA\PhiCmsCore\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Component;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionImageResolver;
use JFA\PhiCmsCore\Sections\SectionRenderer;
use Throwable;

final class SectionView extends Component
{
    /** @var array<string, mixed> */
    public array $content;

    public string $definitionView;

    public ?SectionImageResolver $images = null;

    public string $occurrence;

    public string $source = 'published';

    public bool $unavailable = false;

    public bool $canSelect = false;

    public function __construct(
        SectionRenderer $renderer,
        public Section $section,
        public ?Page $page = null,
        public bool $preview = false,
        ?string $occurrence = null,
    ) {
        $this->occurrence = (string) ($section->pivot?->occurrence_id ?? $occurrence ?? 'legacy-' . $section->getKey());

        try {
            $rendered = $renderer->section($section, $page, $preview);
            $this->content = $rendered['content'];
            $this->definitionView = $rendered['view'];
            $this->source = $rendered['source'] ?? 'published';
            $this->unavailable = $rendered['unavailable'] ?? false;
            if (! $this->unavailable) {
                $this->images = new SectionImageResolver($section, $this->content, $preview, $page);
            }
            $this->canSelect = $preview
                && $section->status === SectionStatus::ACTIVE
                && auth()->user()?->fresh()?->can('viewDraft', $section) === true;
        } catch (Throwable $exception) {
            if ($preview) {
                throw $exception;
            }
            Log::error('Published Section rendering failed.', ['section_id' => $section->getKey(), 'exception_type' => $exception::class]);
            abort(503, 'Content temporarily unavailable.');
        }
    }

    public function render(): View
    {
        return view('phi-cms-core::components.section');
    }
}
