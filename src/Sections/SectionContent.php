<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageContent;
use Throwable;

final class SectionContent
{
    public function __construct(
        private SectionRegistry $registry,
        private SectionImages $images,
    ) {}

    /** @return array<string, mixed> */
    public function published(Section $section): array
    {
        try {
            $fresh = Section::query()->select(['id', 'type', 'content_version', 'published_content', 'legacy_image_sources'])->findOrFail((int) $section->getKey());

            return $this->normalizeSnapshot($fresh, $fresh->published_content, allowRecovery: true);
        } catch (Throwable $exception) {
            Log::error('Unable to render published Section.', ['section_id' => $section->getKey(), 'exception_type' => $exception::class]);
            abort(503, 'Content temporarily unavailable.');
        }
    }

    /** @return array<int, array{view: string, content: array<string, mixed>, source: string, unavailable: bool}> */
    public function renderPage(Page $page, bool $preview): array
    {
        $freshPage = $preview
            ? app(PageContent::class)->authorizePreview($page)
            : Page::query()->published()->findOrFail((int) $page->getKey());
        $columns = ['sections.id', 'sections.type', 'sections.status', 'content_version', 'published_content', 'legacy_image_sources'];
        if ($preview) {
            request()->attributes->set('phi-cms.private', true);
            $columns[] = 'draft_content';
        }
        $relationship = $preview && $freshPage->page_version !== null ? $freshPage->draftSections() : $freshPage->sections();
        if (! $preview) {
            $relationship->where('sections.status', SectionStatus::ACTIVE);
        }
        $sections = $relationship
            ->where(function (Builder $query) use ($preview): void {
                $query->whereNotNull('content_version');
                if (! $preview) {
                    $query->orWhere(fn (Builder $recovery): Builder => $recovery->where('type', 'gallery')->whereNotNull('legacy_image_sources')->whereNotNull('published_content'));
                }
            })->select($columns)->orderByPivot('order_column')->get();
        $actor = $preview ? auth()->user()?->fresh() : null;
        $rendered = [];
        foreach ($sections as $section) {
            if (isset($rendered[$section->id])) {
                continue;
            }

            $view = $this->registry->get($section->type)::view();
            if ($preview && $section->status !== SectionStatus::ACTIVE) {
                $rendered[$section->id] = ['view' => $view, 'content' => [], 'source' => 'unavailable', 'unavailable' => true];

                continue;
            }

            $source = 'published';
            if ($preview && $section->content_version !== null) {
                abort_if($actor === null, 403);
                $source = Gate::forUser($actor)->allows('viewDraft', $section) ? 'draft' : 'published';
            }

            $snapshot = $source === 'draft' ? $section->draft_content : $section->published_content;
            if (! is_array($snapshot)) {
                $rendered[$section->id] = ['view' => $view, 'content' => [], 'source' => 'unavailable', 'unavailable' => true];

                continue;
            }

            $rendered[$section->id] = [
                'view' => $view,
                'content' => $this->normalizeSnapshot($section, $snapshot, allowRecovery: ! $preview),
                'source' => $source,
                'unavailable' => false,
            ];
        }

        return $rendered;
    }

    /** @return array<string, mixed> */
    public function preview(Page $page, Section $section): array
    {
        $this->authorizePage($page);
        $relationship = $page->page_version !== null ? $page->draftSections() : $page->sections();
        abort_unless($relationship->whereKey($section->getKey())->exists(), 403);

        return $this->draft($section);
    }

    /** @return array<string, mixed> */
    public function draft(Section $section): array
    {
        request()->attributes->set('phi-cms.private', true);
        $this->ensureEditingEnabled();
        $fresh = Section::query()->findOrFail((int) $section->getKey());
        $this->authorize('viewDraft', $fresh);

        return $this->normalizeSnapshot($fresh, $fresh->draft_content);
    }

    public function authorizePage(Page $page): Page
    {
        $this->ensureEditingEnabled();
        $fresh = Page::query()->published()->findOrFail($page->getKey());
        $this->authorize('preview', $fresh);

        return $fresh;
    }

    /** @param array<string, mixed> $content */
    public function saveDraft(Section $section, array $content, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();

        $version = $this->transaction(function () use ($section, $content, $expectedVersion): int {
            $fresh = $this->images->lockSection($section, $content);
            $this->authorize('viewDraft', $fresh);
            $this->authorize('update', $fresh);
            $this->checkVersion($fresh, $expectedVersion);
            $normalized = $this->registry->get($fresh->type)::normalize($content);
            $this->images->validateContent($fresh, $normalized);

            DB::table($fresh->getTable())->where('id', $fresh->id)->update([
                'draft_content' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'content_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            $this->logContentAction($fresh, 'draft_saved', 'Section draft saved', $expectedVersion + 1);
            session()->forget($this->reviewKey($fresh));

            return $expectedVersion + 1;
        });
        app(SectionRenderer::class)->forget();

        return $version;
    }

    /** @return array{version: int, content: array<string, mixed>, usages: array<int, array<string, mixed>>} */
    public function review(Section $section, int $expectedVersion): array
    {
        $this->ensureEditingEnabled();

        return $this->transaction(function () use ($section, $expectedVersion): array {
            $fresh = $this->images->lockSection($section);
            $this->authorize('viewDraft', $fresh);
            $this->authorize('publish', $fresh);
            $this->checkVersion($fresh, $expectedVersion);
            $content = $this->normalizeSnapshot($fresh, $fresh->draft_content);
            $this->images->validateContent($fresh, $content);
            $usages = $this->usages($fresh);
            session()->put($this->reviewKey($fresh), [
                'actor' => (string) auth()->id(),
                'version' => $expectedVersion,
                'fingerprint' => hash('sha256', json_encode([$content, $usages], JSON_THROW_ON_ERROR)),
            ]);

            return ['version' => $expectedVersion, 'content' => $content, 'usages' => $usages];
        });
    }

    public function publish(Section $section, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();

        $version = $this->transaction(function () use ($section, $expectedVersion): int {
            $fresh = $this->images->lockSection($section);
            $this->authorize('viewDraft', $fresh);
            $this->authorize('publish', $fresh);
            $this->checkVersion($fresh, $expectedVersion);
            $content = $this->normalizeSnapshot($fresh, $fresh->draft_content);
            $this->images->validateContent($fresh, $content);
            $usages = $this->usages($fresh);
            $review = session()->get($this->reviewKey($fresh));

            if (! is_array($review) || $review['actor'] !== (string) auth()->id() || $review['version'] !== $expectedVersion
                || ! hash_equals($review['fingerprint'], hash('sha256', json_encode([$content, $usages], JSON_THROW_ON_ERROR)))) {
                throw new SectionConflict;
            }

            $this->images->markPublished($fresh, $content);

            DB::table($fresh->getTable())->where('id', $fresh->id)->update([
                'published_content' => json_encode($content, JSON_THROW_ON_ERROR),
                'content_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            $this->logContentAction($fresh, 'published', 'Section published', $expectedVersion + 1);

            return $expectedVersion + 1;
        });
        session()->forget($this->reviewKey($section));
        app(SectionRenderer::class)->forget();

        return $version;
    }

    /** @return array<int, array<string, mixed>> */
    private function usages(Section $section): array
    {
        return DB::table('page_section')->join('pages', 'pages.id', '=', 'page_section.page_id')
            ->where('section_id', $section->id)->orderBy('pages.id')->orderBy('page_section.stage')->orderBy('order_column')
            ->select(['pages.id', 'pages.title', 'pages.slug', 'pages.status', 'pages.published_at', 'page_section.id as assignment_id', 'page_section.stage', 'page_section.occurrence_id', 'page_section.order_column'])
            ->get()->map(fn (object $usage): array => (array) $usage)->all();
    }

    private function checkVersion(Section $section, int $expectedVersion): void
    {
        if ($section->content_version === null || $section->content_version !== $expectedVersion) {
            throw new SectionConflict;
        }
    }

    /** @return array<string, mixed> */
    private function normalizeSnapshot(Section $section, mixed $content, bool $allowRecovery = false): array
    {
        if (($section->content_version === null && ! ($allowRecovery && $section->type === 'gallery' && $section->legacy_image_sources !== null)) || ! is_array($content)) {
            throw ValidationException::withMessages(['content' => "Section {$section->id} ({$section->type}) has no valid adopted snapshot."]);
        }

        return $this->registry->get($section->type)::normalize($content);
    }

    public function authorize(string $ability, Page | Section $record): void
    {
        $actor = auth()->user()?->fresh();
        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize($ability, $record);
    }

    private function ensureEditingEnabled(): void
    {
        abort_unless(config('phi-cms.editing', false), 403);
    }

    private function reviewKey(Section $section): string
    {
        return 'phi-cms.reviews.sections.' . $section->getKey();
    }

    private function transaction(\Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['40001', '40P01', '23505', '55P03'], true)) {
                throw new SectionConflict;
            }

            throw $exception;
        }
    }

    private function logContentAction(Section $section, string $event, string $description, int $version): void
    {
        $connection = config('activitylog.database_connection');
        $table = config('activitylog.table_name', 'activity_log');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        activity()
            ->performedOn($section)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties(['version' => $version])
            ->log($description);
    }
}
