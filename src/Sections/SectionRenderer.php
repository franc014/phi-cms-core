<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Http\Request;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;

final class SectionRenderer
{
    private ?Request $request = null;

    /** @var array<string, array<int, array{view: string, content: array<string, mixed>}>> */
    private array $pages = [];

    /** @return array{view: string, content: array<string, mixed>} */
    public function section(Section $section, ?Page $page, bool $preview): array
    {
        if ($this->request !== request()) {
            $this->forget();
            $this->request = request();
        }
        $content = app(SectionContent::class);
        if ($page !== null) {
            $key = $page->getKey() . ($preview ? ':draft' : ':published');
            $this->pages[$key] ??= $content->renderPage($page, $preview);

            return $this->pages[$key][$section->getKey()] ?? abort(403);
        }
        abort_if($preview, 403);
        $snapshot = $content->published($section);
        $fresh = Section::query()->select(['id', 'type'])->findOrFail((int) $section->getKey());

        return ['view' => app(SectionRegistry::class)->get($fresh->type)::view(), 'content' => $snapshot];
    }

    public function forget(): void
    {
        $this->pages = [];
    }
}
