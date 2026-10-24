<?php

namespace JFA\PhiCmsCore\Pages;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Models\Metatags;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\PageSection;
use JFA\PhiCmsCore\Models\PageUrl;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionRegistry;

final class PageContent
{
    /** @var list<string> */
    private const METATAG_KEYS = [
        'title',
        'description',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_card',
        'twitter_description',
        'twitter_image',
        'robots',
    ];

    /** @var list<string> */
    private const READ_ONLY_IMAGE_KEYS = ['og_image', 'twitter_image'];

    public function __construct(
        private PageRegistry $pages,
        private SectionRegistry $sections,
    ) {}

    /**
     * @return array{page: Page, redirect_slug: string|null}
     */
    public function resolvePublishedSlug(string $slug): array
    {
        $url = PageUrl::query()->where('slug', $slug)->first();

        if ($url === null) {
            $page = Page::query()->published()->where('slug', $slug)->with('metatags')->first();
            abort_if($page === null, 404);

            return ['page' => $page, 'redirect_slug' => null];
        }

        $page = Page::query()->published()->whereKey($url->page_id)->with('metatags')->first();
        abort_if($page === null, 404);

        if ($url->kind === 'current') {
            return ['page' => $page, 'redirect_slug' => null];
        }

        $currentSlug = PageUrl::query()
            ->where('page_id', $page->getKey())
            ->where('kind', 'current')
            ->value('slug') ?? $page->slug;

        abort_if($currentSlug === $slug, 404);

        return ['page' => $page, 'redirect_slug' => $currentSlug];
    }

    /** @return array{title: string, slug: string, description: string, template: string|null, metatags: array<string, mixed>|null} */
    public function published(Page $page): array
    {
        $fresh = Page::query()->published()->with('metatags')->findOrFail($page->getKey());

        return $this->metadataFromPage($fresh);
    }

    public function authorizePreview(Page $page): Page
    {
        $this->ensureEditingEnabled();
        request()->attributes->set('phi-cms.private', true);

        $fresh = Page::query()->with('metatags')->findOrFail($page->getKey());
        $this->authorize('preview', $fresh);

        if ($fresh->page_version === null && $fresh->status !== PageStatus::PUBLISHED) {
            abort(404);
        }

        return $fresh;
    }

    /** @return array{title: string, slug: string, description: string, template: string, metatags: array<string, mixed>|null, version: int} */
    public function preview(Page $page): array
    {
        $fresh = $this->authorizePreview($page);
        $metadata = $this->draftMetadata($fresh);

        return $metadata + ['version' => (int) $fresh->page_version];
    }

    public function previewPage(Page $page): Page
    {
        $fresh = $this->authorizePreview($page);
        $metadata = $this->draftMetadata($fresh);
        $preview = $fresh->newInstance($fresh->getAttributes(), true);
        $preview->setConnection($fresh->getConnectionName());
        $preview->setRawAttributes(array_merge($fresh->getAttributes(), [
            'title' => $metadata['title'],
            'slug' => $metadata['slug'],
            'description' => $metadata['description'],
            'template' => $metadata['template'],
        ]), true);

        if ($metadata['metatags'] === null) {
            $preview->setRelation('metatags', null);
        } else {
            $metatags = new Metatags($metadata['metatags']);
            $metatags->exists = true;
            $metatags->setRawAttributes($metadata['metatags'], true);
            $preview->setRelation('metatags', $metatags);
        }

        return $preview;
    }

    public function templateView(Page $page, bool $preview = false): string
    {
        $templateKey = $preview ? $this->draftMetadata($page)['template'] : $page->template;
        abort_if(! is_string($templateKey) || ! $this->pages->has($templateKey), 503, 'Page template is unavailable.');

        return $this->pages->get($templateKey)::view();
    }

    /** @return array<int, Section> */
    public function previewSections(Page $page): array
    {
        $fresh = $this->authorizePreview($page);

        return $fresh->draftSections()
            ->where('sections.status', SectionStatus::ACTIVE)
            ->with(['pages'])
            ->get()
            ->all();
    }

    /** @return array<int, Section> */
    public function selectableSections(Page $page): array
    {
        $fresh = $this->authorizePreview($page);
        $metadata = $this->draftMetadata($fresh);
        $composition = $this->pages->composition($metadata['template']);
        $allowed = $composition['allowed'];

        if (! auth()->user()?->can('viewAny', new Section)) {
            return [];
        }

        return Section::query()
            ->whereIn('type', $allowed)
            ->where('status', SectionStatus::ACTIVE)
            ->whereNotNull('content_version')
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function saveDraft(Page $page, array $fields, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();
        $this->authorize('update', $page);
        $this->assertAdopted($page);

        try {
            $version = $this->transaction(function () use ($page, $fields, $expectedVersion): int {
                $fresh = $this->lockPage($page->getKey());
                $this->authorize('update', $fresh);
                $this->checkVersion($fresh, $expectedVersion);
                $metadata = $this->validateMetadata($fields, $fresh);
                $this->validateDraftRows($fresh, $metadata['template'], $this->draftRows($fresh), requireRequired: false);

                DB::table('pages')->where('id', $fresh->getKey())->update([
                    'draft_fields' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'page_version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

                return $expectedVersion + 1;
            });

            $this->forgetReview($page);

            return $version;
        } catch (ValidationException|PageConflict $exception) {
            throw $exception;
        }
    }

    /**
     * @param  list<array{pivot_id: int|null, section_id: int, slot?: int|null}>  $rows
     */
    public function saveDraftSections(Page $page, array $rows, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();
        $this->authorize('compose', $page);
        $this->assertAdopted($page);

        if (! array_is_list($rows)) {
            throw ValidationException::withMessages(['sections' => 'Draft sections must be an ordered list.']);
        }

        $requestedSectionIds = [];
        $pivotIds = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['pivot_id', 'section_id', 'slot']) !== []) {
                throw ValidationException::withMessages(["sections.{$index}" => 'Each draft assignment must contain only pivot_id, section_id and slot.']);
            }
            if (! is_int($row['section_id'] ?? null) || $row['section_id'] < 1) {
                throw ValidationException::withMessages(["sections.{$index}.section_id" => 'Choose a valid Section.']);
            }
            if (($row['pivot_id'] ?? null) !== null && (! is_int($row['pivot_id']) || $row['pivot_id'] < 1)) {
                throw ValidationException::withMessages(["sections.{$index}.pivot_id" => 'The draft assignment is invalid.']);
            }
            if (array_key_exists('slot', $row) && $row['slot'] !== null && ! is_int($row['slot'])) {
                throw ValidationException::withMessages(["sections.{$index}.slot" => 'The slot must be an integer or null.']);
            }
            $requestedSectionIds[] = $row['section_id'];
            $pivotId = $row['pivot_id'] ?? null;
            if ($pivotId !== null) {
                if (in_array($pivotId, $pivotIds, true)) {
                    throw ValidationException::withMessages(["sections.{$index}.pivot_id" => 'An occurrence may only be included once.']);
                }
                $pivotIds[] = $pivotId;
            }
        }

        $sectionIds = array_values(array_unique([...$this->sectionIds($page->getKey(), 'draft'), ...$requestedSectionIds]));

        return $this->transaction(function () use ($page, $rows, $expectedVersion, $sectionIds, $pivotIds): int {
            $fresh = $this->lockPage($page->getKey(), $sectionIds);
            $this->authorize('compose', $fresh);
            $this->checkVersion($fresh, $expectedVersion);

            $existing = PageSection::query()
                ->where('page_id', $fresh->getKey())
                ->where('stage', 'draft')
                ->with('section')
                ->orderBy('order_column')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($pivotIds as $pivotId) {
                if (! $existing->has($pivotId)) {
                    throw ValidationException::withMessages(['sections' => "Draft assignment {$pivotId} does not belong to this Page."]);
                }
            }

            $selected = Section::query()->whereIn('id', $sectionIds)->orderBy('id')->get()->keyBy('id');
            $normalizedRows = [];
            foreach ($rows as $index => $row) {
                $section = $selected->get($row['section_id']);
                if ($section === null) {
                    throw ValidationException::withMessages(["sections.{$index}.section_id" => 'The selected Section is unavailable.']);
                }
                $pivotId = $row['pivot_id'] ?? null;
                $pivot = $pivotId === null ? null : $existing->get($pivotId);
                if ($pivot !== null && (int) $pivot->section_id !== $section->getKey()) {
                    throw ValidationException::withMessages(["sections.{$index}.section_id" => 'An existing occurrence cannot be repointed. Remove it and add a new occurrence.']);
                }
                $this->assertSelectableSection($section, $pivot);
                $normalizedRows[] = [
                    'pivot' => $pivot,
                    'section' => $section,
                    'slot' => $row['slot'] ?? null,
                    'order_column' => $index + 1,
                ];
            }

            $metadata = $this->draftMetadata($fresh);
            $this->validateDraftRows($fresh, $metadata['template'], collect($normalizedRows)->map(fn (array $row): Section => $row['section'])->all(), requireRequired: false);
            $keptIds = [];
            foreach ($normalizedRows as $row) {
                $pivot = $row['pivot'];
                if ($pivot instanceof PageSection) {
                    if ($pivot->occurrence_id === null) {
                        $pivot->occurrence_id = (string) Str::uuid();
                    }
                    $keptIds[] = $pivot->getKey();
                    DB::table('page_section')->where('id', $pivot->getKey())->update([
                        'order_column' => $row['order_column'],
                        'slot' => $row['slot'],
                        'updated_at' => now(),
                        'occurrence_id' => $pivot->occurrence_id,
                    ]);
                } else {
                    $keptIds[] = DB::table('page_section')->insertGetId([
                        'page_id' => $fresh->getKey(),
                        'section_id' => $row['section']->getKey(),
                        'order_column' => $row['order_column'],
                        'stage' => 'draft',
                        'occurrence_id' => (string) Str::uuid(),
                        'slot' => $row['slot'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $deleteQuery = DB::table('page_section')
                ->where('page_id', $fresh->getKey())
                ->where('stage', 'draft');
            if ($keptIds !== []) {
                $deleteQuery->whereNotIn('id', $keptIds);
            }
            $deleteQuery->delete();

            DB::table('pages')->where('id', $fresh->getKey())->update([
                'page_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

            $this->forgetReview($fresh);

            return $expectedVersion + 1;
        });
    }

    /** @return array<string, mixed> */
    public function review(Page $page, int $expectedVersion): array
    {
        $this->ensureEditingEnabled();
        $this->authorize('publish', $page);
        $this->assertAdopted($page);

        return $this->transaction(function () use ($page, $expectedVersion): array {
            $fresh = $this->lockPage($page->getKey());
            $this->authorize('publish', $fresh);
            $this->checkVersion($fresh, $expectedVersion);
            $metadata = $this->validateMetadata($this->draftMetadata($fresh), $fresh);
            $rows = $this->draftRows($fresh);
            $review = $this->buildReview($fresh, $metadata, $rows);
            $token = Str::random(64);
            session()->put($this->reviewKey($fresh), [
                'actor' => (string) auth()->id(),
                'version' => $expectedVersion,
                'token' => $token,
                'fingerprint' => $review['fingerprint'],
            ]);

            return $review + ['version' => $expectedVersion, 'token' => $token];
        });
    }

    public function publish(Page $page, int $expectedVersion, string $reviewToken): int
    {
        $this->ensureEditingEnabled();
        $this->authorize('publish', $page);
        $this->assertAdopted($page);

        return $this->transaction(function () use ($page, $expectedVersion, $reviewToken): int {
            $fresh = $this->lockPage($page->getKey(), urls: true);
            $this->authorize('publish', $fresh);
            $this->checkVersion($fresh, $expectedVersion);
            $review = session()->get($this->reviewKey($fresh));
            if (! is_array($review) || $review['actor'] !== (string) auth()->id() || $review['version'] !== $expectedVersion || ! hash_equals((string) $review['token'], $reviewToken)) {
                throw new PageConflict('Publication review is stale or belongs to another actor. Review the saved Page again.');
            }

            $metadata = $this->validateMetadata($this->draftMetadata($fresh), $fresh);
            $rows = $this->draftRows($fresh);
            $currentReview = $this->buildReview($fresh, $metadata, $rows);
            if (! hash_equals((string) $review['fingerprint'], $currentReview['fingerprint'])) {
                throw new PageConflict;
            }

            $this->writePublishedMetadata($fresh, $metadata, $expectedVersion + 1);
            $this->reconcilePublishedRows($fresh, $rows);
            $this->syncUrls($fresh, $metadata['slug']);
            session()->forget($this->reviewKey($fresh));

            return $expectedVersion + 1;
        });
    }

    public function unpublish(Page $page, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();
        $this->authorize('publish', $page);
        $this->assertAdopted($page);

        return $this->transaction(function () use ($page, $expectedVersion): int {
            $fresh = $this->lockPage($page->getKey(), urls: true);
            $this->authorize('publish', $fresh);
            $this->checkVersion($fresh, $expectedVersion);

            DB::table('pages')->where('id', $fresh->getKey())->update([
                'status' => PageStatus::DRAFT->value,
                'published_at' => null,
                'page_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            PageUrl::query()->where('page_id', $fresh->getKey())->delete();
            $this->forgetReview($fresh);

            return $expectedVersion + 1;
        });
    }

    /** @return string Recovery export path */
    public function delete(Page $page, int $expectedVersion): string
    {
        $this->ensureEditingEnabled();
        $this->authorize('delete', $page);
        $this->assertAdopted($page);

        return $this->transaction(function () use ($page, $expectedVersion): string {
            $fresh = $this->lockPage($page->getKey(), urls: true);
            $this->authorize('delete', $fresh);
            $this->checkVersion($fresh, $expectedVersion);

            $export = [
                'page' => $fresh->getRawOriginal(),
                'metatags' => $fresh->metatags?->getRawOriginal(),
                'published_assignments' => $this->rawRows($fresh->getKey(), 'published'),
                'draft_assignments' => $this->rawRows($fresh->getKey(), 'draft'),
                'urls' => PageUrl::query()->where('page_id', $fresh->getKey())->get()->map(fn (PageUrl $url): array => $url->getRawOriginal())->all(),
            ];
            $path = 'phi-cms/pages/'.$fresh->getKey().'/delete-'.now()->format('YmdHis').'-'.Str::random(8).'.json';
            if (! Storage::disk('local')->put($path, json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 'private')) {
                throw new PageConflict('The recovery export could not be written; the Page was not deleted.');
            }

            DB::table('page_section')->where('page_id', $fresh->getKey())->delete();
            DB::table('page_urls')->where('page_id', $fresh->getKey())->delete();
            DB::table('metatags')->where('metatable_type', (new Page)->getMorphClass())->where('metatable_id', $fresh->getKey())->delete();
            DB::table('pages')->where('id', $fresh->getKey())->delete();

            return $path;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(Page $page, string $template): array
    {
        $fresh = Page::query()->with('metatags')->findOrFail($page->getKey());
        $this->pages->get($template);
        if ($fresh->page_version !== null && $fresh->template !== $template) {
            throw new PageConflict("Page {$fresh->getKey()} is already adopted with the [{$fresh->template}] template.");
        }
        $assignments = $this->rawRows($fresh->getKey(), 'published');
        if ($fresh->page_version === null) {
            $unknownPageFields = array_diff(array_keys($fresh->getRawOriginal()), ['id', 'title', 'slug', 'description', 'route', 'status', 'published_at', 'created_at', 'updated_at', 'template', 'draft_fields', 'page_version']);
            if ($unknownPageFields !== []) {
                throw new PageConflict('Unknown Page fields require a preservation contract: '.implode(', ', $unknownPageFields));
            }
            $orders = array_column($assignments, 'order_column');
            if (count($orders) !== count(array_unique($orders)) || in_array(null, $orders, true)) {
                throw new PageConflict("Ambiguous relationship ordering on Page {$fresh->getKey()}; preserve and reconcile the source before adoption.");
            }
            $unknownColumns = array_diff(array_keys($assignments[0] ?? []), ['id', 'page_id', 'section_id', 'order_column', 'stage', 'occurrence_id', 'slot', 'created_at', 'updated_at']);
            if ($unknownColumns !== []) {
                throw new PageConflict('Unknown assignment fields require a preservation contract: '.implode(', ', $unknownColumns));
            }
            if (DB::table('metatags')->where('metatable_type', $fresh->getMorphClass())->where('metatable_id', $fresh->getKey())->count() > 1) {
                throw new PageConflict('Multiple Page metatag rows require explicit reconciliation before adoption.');
            }
        }
        $sections = Section::query()->whereIn('id', array_values(array_unique(array_column($assignments, 'section_id'))))->orderBy('id')->get()->map(fn (Section $section): array => $section->getRawOriginal())->all();
        $inventory = [
            'page' => $fresh->getRawOriginal(),
            'metatags' => $fresh->metatags?->getRawOriginal(),
            'assignments' => $assignments,
            'draft_assignments' => $this->rawRows($fresh->getKey(), 'draft'),
            'urls' => PageUrl::query()->where('page_id', $fresh->getKey())->orderBy('slug')->get()->map(fn (PageUrl $url): array => $url->getRawOriginal())->all(),
            'sections' => $sections,
            'template' => $template,
        ];

        return $inventory + ['fingerprint' => hash('sha256', json_encode($inventory, JSON_THROW_ON_ERROR))];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @param  Closure(array<string, mixed>): void|null  $beforeCommit
     */
    public function adopt(Page $page, string $template, array $inventory, ?Closure $beforeCommit = null): void
    {
        $this->ensureEditingDisabled();
        $this->pages->get($template);

        $this->transaction(function () use ($page, $template, $inventory, $beforeCommit): void {
            $fresh = $this->lockPage($page->getKey(), urls: true);
            if ($fresh->page_version !== null) {
                if ($fresh->template !== $template) {
                    throw new PageConflict("Page {$fresh->getKey()} is already adopted with the [{$fresh->template}] template.");
                }

                return;
            }
            if ($fresh->draft_fields !== null) {
                throw new PageConflict('This Page retains draft or rollback state and requires explicit reconciliation before adoption.');
            }
            $current = $this->inventory($fresh, $template);
            if (($inventory['fingerprint'] ?? null) !== $current['fingerprint']) {
                throw new PageConflict('The Page changed after inventory. Generate and review a fresh adoption report.');
            }
            $assignments = $this->rawRows($fresh->getKey(), 'published');
            $sectionIds = array_values(array_unique(array_column($assignments, 'section_id')));
            $metadata = array_replace($this->metadataFromPage($fresh), ['template' => $template]);
            $this->validateMetadata($metadata, $fresh);
            $sections = Section::query()->whereIn('id', $sectionIds)->orderBy('id')->get()->keyBy('id');
            foreach ($assignments as $assignment) {
                $section = $sections->get($assignment['section_id']);
                if ($section === null) {
                    throw new PageConflict("Section {$assignment['section_id']} disappeared during adoption.");
                }
                $this->assertAdoptableSource($section);
            }

            foreach ($assignments as $assignment) {
                $occurrence = $assignment['occurrence_id'] ?? (string) Str::uuid();
                DB::table('page_section')->where('id', $assignment['id'])->update([
                    'stage' => 'published',
                    'occurrence_id' => $occurrence,
                    'updated_at' => $assignment['updated_at'],
                ]);
                $exists = DB::table('page_section')->where('page_id', $fresh->getKey())->where('stage', 'draft')->where('occurrence_id', $occurrence)->exists();
                if (! $exists) {
                    DB::table('page_section')->insert([
                        'page_id' => $fresh->getKey(),
                        'section_id' => $assignment['section_id'],
                        'order_column' => $assignment['order_column'],
                        'stage' => 'draft',
                        'occurrence_id' => $occurrence,
                        'slot' => $assignment['slot'] ?? null,
                        'created_at' => $assignment['created_at'],
                        'updated_at' => $assignment['updated_at'],
                    ]);
                }
            }
            DB::table('pages')->where('id', $fresh->getKey())->update([
                'template' => $template,
                'draft_fields' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'page_version' => 1,
            ]);
            $this->syncUrls($fresh, $fresh->slug);

            if ($beforeCommit !== null) {
                $beforeCommit($this->inventory($fresh->fresh(), $template));
            }
        });
    }

    /** @return array<string, mixed> */
    private function buildReview(Page $page, array $metadata, array $rows): array
    {
        $this->validateDraftRows($page, $metadata['template'], $rows, requireRequired: true);
        $reviewRows = [];
        foreach ($rows as $row) {
            $section = $row['section'];
            $adopted = $section->content_version !== null;
            $available = $section->status === SectionStatus::ACTIVE
                && (($adopted && is_array($section->published_content)) || (! $adopted && is_array($section->content)));
            $reviewRows[] = [
                'pivot_id' => (int) $row['id'],
                'occurrence_id' => $row['occurrence_id'],
                'section_id' => (int) $section->getKey(),
                'title' => $section->title,
                'type' => $section->type,
                'order_column' => (int) $row['order_column'],
                'source' => $adopted ? 'published' : 'current-live',
                'available' => $available,
                'content_version' => $section->content_version,
                'status' => $section->status->value,
            ];
        }

        $fingerprint = hash('sha256', json_encode([
            'page_id' => $page->getKey(),
            'version' => $page->page_version,
            'metadata' => $metadata,
            'declaration' => $this->pages->declarationHash($metadata['template']),
            'rows' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'occurrence_id' => $row['occurrence_id'],
                'section_id' => $row['section']->getKey(),
                'order_column' => $row['order_column'],
                'slot' => $row['slot'],
                'type' => $row['section']->type,
                'status' => $row['section']->status->value,
                'content_version' => $row['section']->content_version,
                'published_content' => $row['section']->published_content,
                'legacy_source' => $row['section']->content_version === null ? $row['section']->getRawOriginal() : null,
            ], $rows),
        ], JSON_THROW_ON_ERROR));

        return [
            'metadata' => $metadata,
            'sections' => $reviewRows,
            'prospective_result' => [
                'title' => $metadata['title'],
                'slug' => $metadata['slug'],
                'description' => $metadata['description'],
                'section_count' => count($reviewRows),
                'html' => $this->publicationHtml($page, $metadata),
            ],
            'fingerprint' => $fingerprint,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function publicationHtml(Page $page, array $metadata): string
    {
        $view = config('phi-cms.page_publication_view');
        if (! is_string($view) || ! view()->exists($view)) {
            throw new PageConflict('Register the host public-layout review view before reviewing publication.');
        }
        $prospective = clone $page;
        $prospective->forceFill(collect($metadata)->except('metatags')->all());
        $prospective->setRelation('metatags', $metadata['metatags'] === null ? null : new Metatags($metadata['metatags']));
        $sections = $page->draftSections()->where('sections.status', SectionStatus::ACTIVE)->get();
        $prospective->setRelation('sections', $sections);

        return view($view, [
            'page' => $prospective,
            'sections' => $sections,
            'templateView' => $this->pages->get($metadata['template'])::view(),
        ])->render();
    }

    /** @param list<array<string, mixed>> $rows */
    private function validateDraftRows(Page $page, string $template, array $rows, bool $requireRequired): void
    {
        $this->pages->get($template);
        $composition = $this->pages->composition($template);
        $allowed = $composition['allowed'];
        foreach ($rows as $row) {
            $section = $row instanceof Section ? $row : ($row['section'] ?? $row);
            if (! $section instanceof Section) {
                continue;
            }
            $type = (string) $section->type;
            $legacyAllowed = $type === 'about-banner' && ($row instanceof Section || ($row['occurrence_id'] ?? null) !== null);
            if (! in_array($type, $allowed, true) && ! $legacyAllowed) {
                throw ValidationException::withMessages(['sections' => "Section {$section->id} ({$type}) is not compatible with the selected Page template."]);
            }
        }
        if ($requireRequired) {
            foreach ($composition['required'] as $required) {
                $matching = array_filter($rows, function (array|Section $row) use ($required): bool {
                    $section = $row instanceof Section ? $row : ($row['section'] ?? $row);

                    return $section instanceof Section
                        && $section->type === $required
                        && $section->status === SectionStatus::ACTIVE
                        && $section->content_version !== null
                        && is_array($section->published_content);
                });
                if ($matching === []) {
                    throw ValidationException::withMessages(['sections' => "A published active {$required} Section is required before this Page can be published."]);
                }
            }
        }
    }

    private function assertSelectableSection(Section $section, ?PageSection $existing): void
    {
        if ($existing !== null && $section->type === 'about-banner') {
            return;
        }
        if ($section->type === 'about-banner') {
            throw ValidationException::withMessages(['sections' => 'The legacy About occurrence can be restored only from its retained Page assignment.']);
        }
        if (! $this->sections->has((string) $section->type) || $section->content_version === null) {
            throw ValidationException::withMessages(['sections' => "Section {$section->id} is not available through the adopted Section workflow."]);
        }
        $this->authorizeSection('view', $section);
    }

    private function assertAdoptableSource(Section $section): void
    {
        if ($section->type === 'about-banner') {
            return;
        }
        if (! $this->sections->has((string) $section->type) || $section->content_version === null || ! is_array($section->published_content)) {
            throw new PageConflict("Section {$section->id} ({$section->type}) is not an adopted publishable source.");
        }
    }

    /** @param array<string, mixed> $fields */
    private function validateMetadata(array $fields, Page $page): array
    {
        $allowed = ['title', 'slug', 'description', 'template', 'metatags'];
        $unknown = array_diff(array_keys($fields), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'Unknown Page fields: '.implode(', ', $unknown).'.']);
        }
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $fields)) {
                throw ValidationException::withMessages(["fields.{$key}" => "The {$key} field is required."]);
            }
        }
        if (! is_string($fields['title']) || $fields['title'] === '' || mb_strlen($fields['title']) > 100) {
            throw ValidationException::withMessages(['fields.title' => 'The title must be a non-empty string of 100 characters or fewer.']);
        }
        if (! is_string($fields['slug']) || mb_strlen($fields['slug']) > 100 || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $fields['slug'])) {
            throw ValidationException::withMessages(['fields.slug' => 'The slug must be a lowercase hyphen-separated URL segment.']);
        }
        if (! is_string($fields['description'])) {
            throw ValidationException::withMessages(['fields.description' => 'The description must be text.']);
        }
        if (! is_string($fields['template']) || ! $this->pages->has($fields['template'])) {
            throw ValidationException::withMessages(['fields.template' => 'Choose a registered compatible Page template.']);
        }
        if ($fields['metatags'] !== null && ! is_array($fields['metatags'])) {
            throw ValidationException::withMessages(['fields.metatags' => 'SEO metadata must be an object or null.']);
        }

        if (is_array($fields['metatags'])) {
            $unknownMeta = array_diff(array_keys($fields['metatags']), self::METATAG_KEYS);
            if ($unknownMeta !== []) {
                throw ValidationException::withMessages(['fields.metatags' => 'Unknown SEO fields: '.implode(', ', $unknownMeta).'.']);
            }
            foreach (self::METATAG_KEYS as $key) {
                if (! array_key_exists($key, $fields['metatags'])) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => "The {$key} SEO field is required."]);
                }
                $value = $fields['metatags'][$key];
                if ($value !== null && ! is_string($value)) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => 'SEO values must be strings or null.']);
                }
                $limit = match ($key) {
                    'title', 'og_title', 'twitter_title' => 100,
                    'description', 'og_description', 'twitter_description' => null,
                    'twitter_card' => 30,
                    'robots' => 50,
                    default => null,
                };
                if ($limit !== null && $value !== null && mb_strlen($value) > $limit) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => "The {$key} SEO value is too long."]);
                }
                if ($key === 'twitter_card' && $value !== null && ! in_array($value, ['summary', 'summary_large_image'], true)) {
                    throw ValidationException::withMessages(['fields.metatags.twitter_card' => 'Twitter card must be summary or summary_large_image.']);
                }
            }
            $currentMeta = $this->metadataFromPage($page)['metatags'];
            foreach (self::READ_ONLY_IMAGE_KEYS as $key) {
                $currentValue = is_array($currentMeta) ? ($currentMeta[$key] ?? null) : null;
                if ($fields['metatags'][$key] !== $currentValue) {
                    throw ValidationException::withMessages(["fields.metatags.{$key}" => 'SEO image references are read-only in this workflow.']);
                }
            }
        }

        return [
            'title' => $fields['title'],
            'slug' => $fields['slug'],
            'description' => $fields['description'],
            'template' => $fields['template'],
            'metatags' => $fields['metatags'],
        ];
    }

    /** @return array{title: string, slug: string, description: string, template: string|null, metatags: array<string, mixed>|null} */
    private function metadataFromPage(Page $page): array
    {
        $metatags = $page->relationLoaded('metatags') ? $page->metatags : $page->metatags()->first();

        return [
            'title' => (string) $page->title,
            'slug' => (string) $page->slug,
            'description' => (string) $page->description,
            'template' => $page->template,
            'metatags' => $metatags === null ? null : collect(self::METATAG_KEYS)->mapWithKeys(fn (string $key): array => [$key => $metatags->getAttribute($key)])->all(),
        ];
    }

    /** @return array{title: string, slug: string, description: string, template: string, metatags: array<string, mixed>|null} */
    private function draftMetadata(Page $page): array
    {
        $draft = is_array($page->draft_fields) ? $page->draft_fields : null;
        if ($draft === null) {
            $draft = $this->metadataFromPage($page);
        }
        $draft['template'] ??= $page->template;

        return $draft;
    }

    /** @return list<array{id: int, page_id: int, section_id: int, order_column: int, stage: string, occurrence_id: string|null, slot: int|null, section: Section}> */
    private function draftRows(Page $page): array
    {
        $assignments = DB::table('page_section as assignments')
            ->where('assignments.page_id', $page->getKey())
            ->where('assignments.stage', 'draft')
            ->orderBy('assignments.order_column')
            ->orderBy('assignments.id')
            ->get([
                'assignments.id',
                'assignments.page_id',
                'assignments.section_id',
                'assignments.order_column',
                'assignments.stage',
                'assignments.occurrence_id',
                'assignments.slot',
            ]);
        $sections = Section::query()->whereIn('id', $assignments->pluck('section_id')->unique()->all())->get()->keyBy('id');

        return $assignments->map(function (object $row) use ($sections): array {
            $section = $sections->get($row->section_id);
            if (! $section instanceof Section) {
                throw new PageConflict("Section {$row->section_id} disappeared from the Page draft.");
            }

            return [
                'id' => (int) $row->id,
                'page_id' => (int) $row->page_id,
                'section_id' => (int) $row->section_id,
                'order_column' => (int) $row->order_column,
                'stage' => (string) $row->stage,
                'occurrence_id' => $row->occurrence_id,
                'slot' => $row->slot === null ? null : (int) $row->slot,
                'section' => $section,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function rawRows(int $pageId, string $stage): array
    {
        if (DB::table('page_section')->where('page_id', $pageId)->where('stage', $stage)->count() > 1000) {
            throw new PageConflict('Page inventory exceeds the 1000-assignment safety bound. Reduce the explicit scope before continuing.');
        }

        return DB::table('page_section')->where('page_id', $pageId)->where('stage', $stage)->orderBy('order_column')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /** @return list<int> */
    private function sectionIds(int $pageId, string $stage): array
    {
        return DB::table('page_section')->where('page_id', $pageId)->where('stage', $stage)->orderBy('section_id')->pluck('section_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @param list<int> $sectionIds */
    private function lockSections(array $sectionIds): void
    {
        if ($sectionIds !== []) {
            Section::query()->whereIn('id', array_values(array_unique($sectionIds)))->orderBy('id')->lockForUpdate()->get();
        }
    }

    /** @param list<int> $additionalSections */
    private function lockPage(int $pageId, array $additionalSections = [], bool $urls = false): Page
    {
        $snapshot = Page::query()->findOrFail($pageId);
        $references = $this->allSectionIds($pageId);
        $slugs = $this->urlLockSet($snapshot);
        $claims = [];
        if ($urls) {
            $claims = $this->lockPageUrls($pageId, $slugs);
        }
        $this->lockSections([...$references, ...$additionalSections]);
        $fresh = Page::query()->with('metatags')->lockForUpdate()->findOrFail($pageId);
        if ($references !== $this->allSectionIds($pageId) || ($urls && $slugs !== $this->urlLockSet($fresh))) {
            throw new PageConflict('The Page reference or URL lock set changed. Reload latest and review again.');
        }
        if ($urls && $claims !== $this->urlClaims($pageId, $slugs)->get()->map(fn (PageUrl $url): array => $url->getAttributes())->all()) {
            throw new PageConflict('URL ownership changed while waiting. Reload latest and review again.');
        }

        return $fresh;
    }

    /** @return list<int> */
    private function allSectionIds(int $pageId): array
    {
        return DB::table('page_section')->where('page_id', $pageId)->distinct()->orderBy('section_id')->pluck('section_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return list<string> */
    private function urlLockSet(Page $page): array
    {
        $slugs = PageUrl::query()->where('page_id', $page->getKey())->pluck('slug')->all();
        $slugs[] = $page->slug;
        $slugs[] = $page->draft_fields['slug'] ?? $page->slug;
        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function lockPageUrls(int $pageId, array $slugs = []): array
    {
        return $this->urlClaims($pageId, $slugs)->lockForUpdate()->get()->map(fn (PageUrl $url): array => $url->getAttributes())->all();
    }

    /** @param list<string> $slugs */
    private function urlClaims(int $pageId, array $slugs): Builder
    {
        return PageUrl::query()
            ->where(function (Builder $query) use ($pageId, $slugs): void {
                $query->where('page_id', $pageId);
                if ($slugs !== []) {
                    $query->orWhereIn('slug', array_values(array_unique($slugs)));
                }
            })
            ->orderBy('slug');
    }

    /** @param list<array<string, mixed>> $rows */
    private function reconcilePublishedRows(Page $page, array $rows): void
    {
        $existing = collect($this->rawRows($page->getKey(), 'published'))->keyBy('occurrence_id');
        $kept = [];
        foreach ($rows as $row) {
            if (! is_string($row['occurrence_id']) || $row['occurrence_id'] === '') {
                throw new PageConflict('A draft occurrence has no stable identity. Reload the Page composition.');
            }
            $kept[] = $row['occurrence_id'];
            $current = $existing->get($row['occurrence_id']);
            if ($current === null) {
                DB::table('page_section')->insert([
                    'page_id' => $page->getKey(),
                    'section_id' => $row['section_id'],
                    'order_column' => $row['order_column'],
                    'stage' => 'published',
                    'occurrence_id' => $row['occurrence_id'],
                    'slot' => $row['slot'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('page_section')->where('id', $current['id'])->update([
                    'section_id' => $row['section_id'],
                    'order_column' => $row['order_column'],
                    'slot' => $row['slot'],
                    'updated_at' => now(),
                ]);
            }
        }
        $deleteQuery = DB::table('page_section')->where('page_id', $page->getKey())->where('stage', 'published');
        if ($kept !== []) {
            $deleteQuery->whereNotIn('occurrence_id', $kept);
        }
        $deleteQuery->delete();
    }

    /** @param array<string, mixed> $metadata */
    private function writePublishedMetadata(Page $page, array $metadata, int $version): void
    {
        DB::table('pages')->where('id', $page->getKey())->update([
            'title' => $metadata['title'],
            'slug' => $metadata['slug'],
            'description' => $metadata['description'],
            'template' => $metadata['template'],
            'status' => PageStatus::PUBLISHED->value,
            'published_at' => now(),
            'page_version' => $version,
            'updated_at' => now(),
        ]);
        $query = DB::table('metatags')->where('metatable_type', (new Page)->getMorphClass())->where('metatable_id', $page->getKey());
        if ($metadata['metatags'] === null) {
            $query->delete();

            return;
        }
        $values = $metadata['metatags'] + [
            'metatable_type' => (new Page)->getMorphClass(),
            'metatable_id' => $page->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $existing = $query->first();
        if ($existing === null) {
            DB::table('metatags')->insert($values);
        } else {
            DB::table('metatags')->where('id', $existing->id)->update([...$metadata['metatags'], 'updated_at' => now()]);
        }
    }

    private function syncUrls(Page $page, string $newSlug): void
    {
        $current = PageUrl::query()->where('slug', $newSlug)->lockForUpdate()->first();
        if ($current !== null && ((int) $current->page_id !== (int) $page->getKey() && $current->kind === 'current')) {
            throw ValidationException::withMessages(['fields.slug' => "The slug [{$newSlug}] is already the current URL of another Page."]);
        }
        if ($current !== null && (int) $current->page_id !== (int) $page->getKey()) {
            $current->delete();
        }

        $oldSlug = (string) $page->getRawOriginal('slug');
        if ($oldSlug !== $newSlug) {
            $old = PageUrl::query()->where('slug', $oldSlug)->lockForUpdate()->first();
            if ($old === null) {
                PageUrl::query()->create(['slug' => $oldSlug, 'page_id' => $page->getKey(), 'kind' => 'redirect']);
            } elseif ((int) $old->page_id === (int) $page->getKey()) {
                $old->update(['page_id' => $page->getKey(), 'kind' => 'redirect']);
            }
        }
        PageUrl::query()->updateOrCreate(['slug' => $newSlug], ['page_id' => $page->getKey(), 'kind' => 'current']);
    }

    private function assertAdopted(Page $page): void
    {
        if ($page->page_version === null || ! is_string($page->template) || ! $this->pages->has($page->template)) {
            throw ValidationException::withMessages(['page' => 'This Page has not completed a supported adoption.']);
        }
    }

    private function authorize(string $ability, Page|Section $record): void
    {
        $actor = auth()->user()?->fresh();
        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize($ability, $record);
    }

    private function authorizeSection(string $ability, Section $section): void
    {
        $actor = auth()->user()?->fresh();
        abort_if($actor === null, 403);
        Gate::forUser($actor)->authorize($ability, $section);
    }

    private function ensureEditingEnabled(): void
    {
        abort_unless(config('phi-cms.editing', false), 403);
    }

    private function ensureEditingDisabled(): void
    {
        abort_if(config('phi-cms.editing', false), 403, 'Disable Page editing before adoption or rollback.');
    }

    private function checkVersion(Page $page, int $expectedVersion): void
    {
        if ($page->page_version === null || (int) $page->page_version !== $expectedVersion) {
            throw new PageConflict;
        }
    }

    private function forgetReview(Page $page): void
    {
        session()->forget($this->reviewKey($page));
    }

    private function reviewKey(Page $page): string
    {
        return 'phi-cms.reviews.pages.'.$page->getKey();
    }

    private function transaction(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['40001', '40P01', '23505', '55P03'], true)) {
                throw new PageConflict;
            }

            throw $exception;
        }
    }
}
