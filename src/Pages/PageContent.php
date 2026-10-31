<?php

namespace JFA\PhiCmsCore\Pages;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
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
    private const SUBMISSIONS_TABLE = 'phi_cms_page_action_submissions';

    /** @var list<string> */
    private const CREATION_FIELDS = ['title', 'slug', 'description', 'template'];

    /** @var list<string> */
    private const SECTION_CREATION_FIELDS = ['title'];

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
     * Issue a server-side token for one modal submission.
     *
     * The token is deliberately private to the authenticated actor and
     * session. It allows a lost response to be retried without creating a
     * second Page or Section while keeping the public write methods useful to
     * trusted package callers that do not need idempotency.
     */
    public function issueSubmissionToken(string $operation, ?int $pageId = null, ?int $sourceId = null): string
    {
        $this->ensureEditingEnabled();

        if (! in_array($operation, ['create-page', 'create-section', 'copy-section'], true)) {
            throw ValidationException::withMessages(['submission' => 'The submission action is invalid.']);
        }

        $actor = auth()->user()?->fresh();
        abort_if($actor === null, 403);

        $page = $pageId === null ? null : Page::query()->findOrFail($pageId);
        if ($operation === 'create-page') {
            abort_if($page !== null || $sourceId !== null, 422);
            Gate::forUser($actor)->authorize('create', new Page);
            Gate::forUser($actor)->authorize('preview', new Page);
        } else {
            abort_if($page === null || $sourceId !== null && $operation !== 'copy-section', 422);
            Gate::forUser($actor)->authorize('preview', $page);
            Gate::forUser($actor)->authorize('compose', $page);
            Gate::forUser($actor)->authorize('create', new Section);
            $this->assertAdopted($page);
        }

        $token = Str::random(64);
        DB::table(self::SUBMISSIONS_TABLE)->insert([
            'token' => $token,
            'operation' => $operation,
            'actor_id' => (string) $actor->getAuthIdentifier(),
            'session_id' => session()->getId(),
            'page_id' => $page?->getKey(),
            'source_id' => $sourceId,
            'fingerprint' => null,
            'result' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /**
     * Create the private Page identity before any relationship composition.
     *
     * @param  array<string, mixed>  $fields
     */
    public function createDraft(array $fields, ?string $submissionToken = null): Page
    {
        $this->ensureEditingEnabled();
        $this->authorize('create', new Page);
        $this->authorize('preview', new Page);
        $metadata = $this->validateCreationMetadata($fields);
        $fingerprint = $this->submissionFingerprint('create-page', null, null, $metadata);

        return $this->transaction(function () use ($metadata, $fingerprint, $submissionToken): Page {
            $this->authorize('create', new Page);
            $this->authorize('preview', new Page);
            $previous = $this->lockSubmission($submissionToken, 'create-page', null, null, $fingerprint);
            if ($previous !== null) {
                return Page::query()->findOrFail((int) $previous['page_id']);
            }

            $now = now();
            $pageId = DB::table('pages')->insertGetId([
                'title' => $metadata['title'],
                'slug' => $metadata['slug'],
                'description' => $metadata['description'],
                'status' => PageStatus::DRAFT->value,
                'route' => '',
                'published_at' => null,
                'template' => $metadata['template'],
                'draft_fields' => json_encode($metadata + ['metatags' => $this->emptyMetatags()], JSON_THROW_ON_ERROR),
                'page_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = ['page_id' => (int) $pageId];
            $this->completeSubmission($submissionToken, $result);

            return Page::query()->findOrFail($pageId);
        });
    }

    /**
     * Create a valid unpublished Services Section and its draft occurrence
     * atomically.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $content
     * @return array{section_id: int, page_version: int}
     */
    public function createSection(Page $page, array $fields, array $content, int $expectedVersion, ?string $submissionToken = null): array
    {
        $this->ensureEditingEnabled();
        $this->authorize('preview', $page);
        $this->authorize('compose', $page);
        $this->authorize('create', new Section);
        $title = $this->validateSectionCreationFields($fields);
        $normalized = $this->normalizeServicesContent($content);
        $fingerprint = $this->submissionFingerprint('create-section', (int) $page->getKey(), null, [
            'title' => $title,
            'content' => $normalized,
            'version' => $expectedVersion,
        ]);

        return $this->transaction(function () use ($page, $title, $normalized, $expectedVersion, $submissionToken, $fingerprint): array {
            $previous = $this->lockSubmission($submissionToken, 'create-section', (int) $page->getKey(), null, $fingerprint);
            $fresh = $this->lockPage($page->getKey());
            $this->authorize('preview', $fresh);
            $this->authorize('compose', $fresh);
            $this->authorize('create', new Section);
            if ($previous !== null) {
                return [
                    'section_id' => (int) $previous['section_id'],
                    'page_version' => (int) $previous['page_version'],
                ];
            }
            $this->checkVersion($fresh, $expectedVersion);
            $this->assertServicesCreationPage($fresh);
            $normalized = $this->normalizeServicesContent($normalized);

            $sectionId = $this->insertNewServicesSection($title, $normalized);
            $newVersion = $this->appendDraftSection($fresh, $sectionId, $expectedVersion);
            $result = ['section_id' => $sectionId, 'page_version' => $newVersion];
            $this->completeSubmission($submissionToken, $result);
            $this->forgetReview($fresh);

            return $result;
        });
    }

    /**
     * Copy only the current published Services snapshot into a new Section.
     *
     * @param  array<string, mixed>  $fields
     * @return array{section_id: int, page_version: int}
     */
    public function copySection(Page $page, Section $source, array $fields, int $expectedVersion, int $expectedSourceVersion, ?string $submissionToken = null): array
    {
        $this->ensureEditingEnabled();
        $this->authorize('preview', $page);
        $this->authorize('compose', $page);
        $this->authorize('create', new Section);
        $this->authorizeCopySourceAccess($source);
        if ($submissionToken === null) {
            throw ValidationException::withMessages(['source' => 'Review the published source before copying it.']);
        }
        $title = $this->validateSectionCreationFields($fields);
        $fingerprint = $this->submissionFingerprint('copy-section', (int) $page->getKey(), (int) $source->getKey(), [
            'title' => $title,
            'version' => $expectedVersion,
            'source_version' => $expectedSourceVersion,
        ]);

        return $this->transaction(function () use ($page, $source, $title, $expectedVersion, $expectedSourceVersion, $submissionToken, $fingerprint): array {
            $previous = $this->lockSubmission($submissionToken, 'copy-section', (int) $page->getKey(), (int) $source->getKey(), $fingerprint);
            $fresh = $this->lockPage($page->getKey(), [(int) $source->getKey()]);
            $this->authorize('preview', $fresh);
            $this->authorize('compose', $fresh);
            $sourceFresh = Section::query()->findOrFail((int) $source->getKey());
            $this->authorizeCopySourceAccess($sourceFresh);
            $this->authorize('create', new Section);
            if ($previous !== null) {
                return [
                    'section_id' => (int) $previous['section_id'],
                    'page_version' => (int) $previous['page_version'],
                ];
            }
            $this->checkVersion($fresh, $expectedVersion);
            $this->assertServicesCreationPage($fresh);
            $this->assertCopySource($sourceFresh, $expectedSourceVersion);
            $normalized = $this->normalizeServicesContent($sourceFresh->published_content);
            if ($this->sections->get('services')::imageReferences($normalized) !== [] || $sourceFresh->getMedia()->isNotEmpty()) {
                throw ValidationException::withMessages(['source' => 'Only text-only published Services Sections can be copied.']);
            }
            $this->assertSubmissionReview($submissionToken, (int) $fresh->getKey(), $sourceFresh, $normalized);

            $sectionId = $this->insertNewServicesSection($title, $normalized);
            $newVersion = $this->appendDraftSection($fresh, $sectionId, $expectedVersion);
            $result = ['section_id' => $sectionId, 'page_version' => $newVersion];
            $this->completeSubmission($submissionToken, $result);
            $this->forgetReview($fresh);

            return $result;
        });
    }

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
            ->with(['pages'])
            ->get()
            ->all();
    }

    /** @return array<int, Section> */
    public function restorableLegacySections(Page $page): array
    {
        $fresh = $this->authorizePreview($page);
        $draftOccurrences = DB::table('page_section')
            ->where('page_id', $fresh->getKey())
            ->where('stage', 'draft')
            ->whereNotNull('occurrence_id')
            ->pluck('occurrence_id')
            ->all();

        $query = DB::table('page_legacy_occurrences')->where('page_id', $fresh->getKey());
        if ($draftOccurrences !== []) {
            $query->whereNotIn('occurrence_id', $draftOccurrences);
        }

        return Section::query()->whereIn('id', $query->pluck('section_id'))
            ->where('type', 'about-banner')->with(['pages'])->orderBy('id')->get()->all();
    }

    /** @return array<int, Section> */
    public function selectableSections(Page $page): array
    {
        $fresh = $this->authorizePreview($page);
        $metadata = $this->draftMetadata($fresh);
        $composition = $this->pages->composition($metadata['template']);
        $allowed = $composition['allowed'];

        $actor = auth()->user()?->fresh();
        if ($actor === null || ! Gate::forUser($actor)->allows('viewAny', new Section)) {
            return [];
        }

        return Section::query()
            ->whereIn('type', $allowed)
            ->where('status', SectionStatus::ACTIVE)
            ->whereNotNull('content_version')
            ->select(['id', 'title', 'type', 'status', 'content_version', 'published_content'])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->filter(fn (Section $section): bool => Gate::forUser($actor)->allows('view', $section)
                && Gate::forUser($actor)->allows('useInPage', $section))
            ->values()
            ->all();
    }

    /**
     * @return list<array{section: Section, usage_count: int}>
     */
    public function selectableSectionChoices(Page $page): array
    {
        $sections = $this->selectableSections($page);
        $actor = auth()->user()?->fresh();

        if ($sections === [] || ! $actor instanceof Authenticatable) {
            return [];
        }

        $gate = Gate::forUser($actor);
        $usageCounts = PageSection::query()
            ->whereIn('section_id', array_map(
                static fn (Section $section): int => (int) $section->getKey(),
                $sections,
            ))
            ->with('page')
            ->get()
            ->filter(function (PageSection $assignment) use ($gate): bool {
                $page = $assignment->page;

                return $page instanceof Page
                    && ($gate->allows('view', $page) || $gate->allows('preview', $page));
            })
            ->groupBy('section_id')
            ->map(static fn (Collection $assignments): int => $assignments->count());

        return array_map(
            static fn (Section $section): array => [
                'section' => $section,
                'usage_count' => (int) $usageCounts->get($section->getKey(), 0),
            ],
            $sections,
        );
    }

    /** @return array<int, Section> */
    public function copyableSections(Page $page): array
    {
        $fresh = $this->authorizePreview($page);
        $this->authorize('compose', $fresh);
        $this->authorize('create', new Section);
        $actor = auth()->user()?->fresh();
        if ($actor === null || ! Gate::forUser($actor)->allows('viewAny', new Section)) {
            return [];
        }

        return Section::query()
            ->where('type', 'services')
            ->where('status', SectionStatus::ACTIVE)
            ->whereNotNull('content_version')
            ->whereNotNull('published_content')
            ->select(['id', 'title', 'type', 'status', 'content_version'])
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->filter(fn (Section $section): bool => Gate::forUser($actor)->allows('view', $section)
                && Gate::forUser($actor)->allows('useInPage', $section)
                && Gate::forUser($actor)->allows('replicate', $section))
            ->values()
            ->all();
    }

    /** @return array{version: int, definition_fingerprint: string, content: array<string, mixed>} */
    public function copyableSectionPreview(Page $page, int $sourceId, ?string $submissionToken = null): array
    {
        $freshPage = $this->authorizePreview($page);
        $this->authorize('compose', $freshPage);
        $source = Section::query()->findOrFail($sourceId);
        $this->authorizeCopySource($source, (int) $source->content_version);
        $content = $this->normalizeServicesContent($source->published_content);
        if ($this->sections->get('services')::imageReferences($content) !== [] || $source->getMedia()->isNotEmpty()) {
            throw ValidationException::withMessages(['source' => 'Only text-only published Services Sections can be copied.']);
        }
        $this->recordSubmissionReview(
            $submissionToken,
            (int) $freshPage->getKey(),
            (int) $source->getKey(),
            $this->sourceCopyFingerprint($source, $content),
        );

        return [
            'version' => (int) $source->content_version,
            'definition_fingerprint' => $this->sectionDefinitionFingerprint('services'),
            'content' => $content,
        ];
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
        } catch (ValidationException | PageConflict $exception) {
            throw $exception;
        }
    }

    /**
     * @param  list<array{pivot_id: int|null, section_id: int, slot?: string|null}>  $rows
     */
    public function saveDraftSections(Page $page, array $rows, int $expectedVersion): int
    {
        $this->ensureEditingEnabled();
        $this->authorize('preview', $page);
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
            if (array_key_exists('slot', $row) && $row['slot'] !== null && ! is_string($row['slot'])) {
                throw ValidationException::withMessages(["sections.{$index}.slot" => 'The slot must be a declared name or null.']);
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
            $this->authorize('preview', $fresh);
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
            $legacyOccurrenceProvenance = DB::table('page_legacy_occurrences')
                ->where('page_id', $fresh->getKey())
                ->whereIn('section_id', $sectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('section_id');
            $reservedLegacyOccurrences = $existing->pluck('occurrence_id')->all();

            foreach ($pivotIds as $pivotId) {
                if (! $existing->has($pivotId)) {
                    throw ValidationException::withMessages(['sections' => "Draft assignment {$pivotId} does not belong to this Page."]);
                }
            }

            $selected = Section::query()->whereIn('id', $sectionIds)->orderBy('id')->get()->keyBy('id');
            $metadata = $this->draftMetadata($fresh);
            $composition = $this->pages->composition($metadata['template']);
            $fixedSlotBaselines = $composition['mode'] === 'fixed'
                ? collect($this->rawRows($fresh->getKey(), 'published'))->keyBy('slot')
                : collect();
            $normalizedRows = [];
            foreach ($rows as $index => $row) {
                $section = $selected->get($row['section_id']);
                if ($section === null) {
                    throw ValidationException::withMessages(["sections.{$index}.section_id" => 'The selected Section is unavailable.']);
                }
                $pivotId = $row['pivot_id'] ?? null;
                $pivot = $pivotId === null ? null : $existing->get($pivotId);
                $replacing = $pivot !== null && (int) $pivot->section_id !== $section->getKey();
                $selectableSlot = $composition['mode'] === 'fixed'
                    && ($composition['slots'][$row['slot'] ?? '']['selectable'] ?? false);
                if ($replacing && ! $selectableSlot) {
                    throw ValidationException::withMessages(["sections.{$index}.section_id" => 'An existing occurrence cannot be repointed. Remove it and add a new occurrence.']);
                }
                $provenance = null;
                if ($pivot === null && $section->type === 'about-banner') {
                    $originalOccurrences = $legacyOccurrenceProvenance->get($section->getKey(), collect());
                    $provenance = $originalOccurrences->first(fn (object $original): bool => ! in_array($original->occurrence_id, $reservedLegacyOccurrences, true));
                    if ($provenance !== null) {
                        $reservedLegacyOccurrences[] = $provenance->occurrence_id;
                    } elseif ($originalOccurrences->isNotEmpty()) {
                        throw ValidationException::withMessages(["sections.{$index}.section_id" => 'Each retained legacy About occurrence may only be restored once. No missing original occurrence remains.']);
                    }
                }
                if ($composition['mode'] === 'fixed' && $pivot !== null && $pivot->slot !== ($row['slot'] ?? null)) {
                    throw ValidationException::withMessages(["sections.{$index}.slot" => 'An existing fixed occurrence cannot move to another slot.']);
                }
                $this->assertSelectableSection($section, $replacing ? null : $pivot, $provenance);
                $occurrenceId = $pivot !== null ? $pivot->occurrence_id : ($provenance !== null ? $provenance->occurrence_id : null);
                if ($composition['mode'] === 'fixed' && $pivot === null
                    && ! $existing->contains(fn (PageSection $assignment): bool => $assignment->slot === ($row['slot'] ?? null))) {
                    $baseline = $fixedSlotBaselines->get($row['slot'] ?? '');
                    $occurrenceId = $baseline['occurrence_id'] ?? null;
                }
                $normalizedRows[] = [
                    'pivot' => $pivot,
                    'section' => $section,
                    'slot' => $row['slot'] ?? null,
                    'order_column' => $index + 1,
                    'occurrence_id' => $occurrenceId,
                ];
            }

            $this->validateDraftRows($fresh, $metadata['template'], $normalizedRows, requireRequired: false);
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
                        'section_id' => $row['section']->getKey(),
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
                        'occurrence_id' => $row['occurrence_id'] ?? (string) Str::uuid(),
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
            PageUrl::query()->where('page_id', $fresh->getKey())->where('kind', 'current')->update(['kind' => 'redirect']);
            $this->forgetReview($fresh);

            return $expectedVersion + 1;
        });
    }

    public function delete(Page $page, int $expectedVersion): void
    {
        $this->ensureEditingEnabled();
        $this->authorize('delete', $page);
        $this->assertAdopted($page);

        $this->transaction(function () use ($page, $expectedVersion): void {
            $fresh = $this->lockPage($page->getKey(), urls: true);
            $this->authorize('delete', $fresh);
            $this->checkVersion($fresh, $expectedVersion);

            DB::table('page_section')->where('page_id', $fresh->getKey())->delete();
            DB::table('page_legacy_occurrences')->where('page_id', $fresh->getKey())->delete();
            DB::table('page_urls')->where('page_id', $fresh->getKey())->delete();
            DB::table('metatags')->where('metatable_type', (new Page)->getMorphClass())->where('metatable_id', $fresh->getKey())->delete();
            DB::table('pages')->where('id', $fresh->getKey())->delete();

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
        $fixed = $composition['mode'] === 'fixed';
        $slots = $composition['slots'] ?? [];
        $positions = array_flip(array_keys($slots));
        $seenSlots = [];
        $lastPosition = -1;
        $baseline = [];
        if ($fixed && $page->page_version !== null) {
            foreach ([...$this->rawRows($page->getKey(), 'published'), ...$this->rawRows($page->getKey(), 'draft')] as $assignment) {
                if ($assignment['slot'] !== null) {
                    $baseline[$assignment['slot']] ??= $assignment;
                }
            }
        }
        foreach ($rows as $row) {
            $section = $row['section'] ?? null;
            if (! $section instanceof Section) {
                throw ValidationException::withMessages(['sections' => 'A selected Section is unavailable.']);
            }
            $slot = $row['slot'] ?? null;
            if (! $fixed && $slot !== null) {
                throw ValidationException::withMessages(['sections' => 'Ordered Page templates do not accept fixed slots.']);
            }
            if ($fixed) {
                if (! is_string($slot) || ! isset($slots[$slot]) || isset($seenSlots[$slot])) {
                    throw ValidationException::withMessages(['sections' => 'Choose each declared fixed slot at most once.']);
                }
                if ($positions[$slot] <= $lastPosition) {
                    throw ValidationException::withMessages(['sections' => 'Fixed slots must retain their declared order.']);
                }
                if ($section->type !== $slots[$slot]['type']) {
                    throw ValidationException::withMessages(['sections' => "The Section is not compatible with fixed slot {$slot}."]);
                }
                $original = $baseline[$slot] ?? null;
                if ($page->page_version !== null) {
                    $selectable = $slots[$slot]['selectable'] ?? false;
                    if ((! $selectable && ($original === null || (int) $original['section_id'] !== $section->getKey()))
                        || ($original !== null && $original['occurrence_id'] !== ($row['occurrence_id'] ?? null))) {
                        throw ValidationException::withMessages(['sections' => "Fixed slot {$slot} must retain its occurrence identity; replacement is disabled unless explicitly enabled."]);
                    }
                }
                $seenSlots[$slot] = $section;
                $lastPosition = $positions[$slot];
            }
            $type = (string) $section->type;
            $legacyAllowed = ! $fixed && $type === 'about-banner' && ($page->page_version === null || ($row['occurrence_id'] ?? null) !== null);
            if (! in_array($type, $allowed, true) && ! $legacyAllowed) {
                throw ValidationException::withMessages(['sections' => "Section {$section->id} ({$type}) is not compatible with the selected Page template."]);
            }
        }
        if ($requireRequired) {
            foreach ($slots as $slot => $definition) {
                $section = $seenSlots[$slot] ?? null;
                if (($definition['required'] ?? true) && ($section === null || $section->status !== SectionStatus::ACTIVE
                    || $section->content_version === null || ! is_array($section->published_content))) {
                    throw ValidationException::withMessages(['sections' => "A published active Section is required in fixed slot {$slot} before publication."]);
                }
            }
            foreach ($composition['required'] as $required) {
                $matching = array_filter($rows, function (array | Section $row) use ($required): bool {
                    $section = $row instanceof Section ? $row : ($row['section'] ?? null);

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

    private function assertSelectableSection(Section $section, ?PageSection $existing, ?object $provenance = null): void
    {
        if ($section->type === 'about-banner' && ($existing !== null || $provenance !== null)) {
            return;
        }
        if ($section->type === 'about-banner') {
            throw ValidationException::withMessages(['sections' => 'The legacy About occurrence can be restored only from its retained Page assignment.']);
        }
        if ($existing === null) {
            $actor = auth()->user()?->fresh();
            abort_if($actor === null, 403);
            Gate::forUser($actor)->authorize('viewAny', new Section);
        }
        if ($existing === null && $section->status !== SectionStatus::ACTIVE) {
            throw ValidationException::withMessages(['sections' => "Section {$section->id} is inactive and cannot be added to a Page draft."]);
        }
        if (! $this->sections->has((string) $section->type) || $section->content_version === null) {
            throw ValidationException::withMessages(['sections' => "Section {$section->id} is not available through the adopted Section workflow."]);
        }
        $this->authorizeSection('view', $section);
        if ($existing === null) {
            $this->authorizeSection('useInPage', $section);
        }
    }

    /** @param array<string, mixed> $fields */
    private function validateMetadata(array $fields, Page $page): array
    {
        $allowed = ['title', 'slug', 'description', 'template', 'metatags'];
        $unknown = array_diff(array_keys($fields), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'Unknown Page fields: ' . implode(', ', $unknown) . '.']);
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
        if ($page->page_version !== null && $fields['template'] !== $page->template) {
            throw ValidationException::withMessages(['fields.template' => 'The Page template is developer-controlled after adoption.']);
        }
        if ($fields['metatags'] !== null && ! is_array($fields['metatags'])) {
            throw ValidationException::withMessages(['fields.metatags' => 'SEO metadata must be an object or null.']);
        }

        if (is_array($fields['metatags'])) {
            $unknownMeta = array_diff(array_keys($fields['metatags']), self::METATAG_KEYS);
            if ($unknownMeta !== []) {
                throw ValidationException::withMessages(['fields.metatags' => 'Unknown SEO fields: ' . implode(', ', $unknownMeta) . '.']);
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
        }

        $currentMeta = $this->metadataFromPage($page)['metatags'];
        foreach (self::READ_ONLY_IMAGE_KEYS as $key) {
            $currentValue = is_array($currentMeta) ? ($currentMeta[$key] ?? null) : null;
            $submittedValue = is_array($fields['metatags']) ? $fields['metatags'][$key] : null;
            if ($submittedValue !== $currentValue) {
                throw ValidationException::withMessages(["fields.metatags.{$key}" => 'SEO image references are read-only in this workflow.']);
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

    /** @return list<array{id: int, page_id: int, section_id: int, order_column: int, stage: string, occurrence_id: string|null, slot: string|null, section: Section}> */
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
                'slot' => $row->slot === null ? null : (string) $row->slot,
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
        $hadPublicPublication = $page->getRawOriginal('published_at') !== null
            || PageUrl::query()->where('page_id', $page->getKey())->exists();
        $pageClaim = Page::query()
            ->whereKeyNot($page->getKey())
            ->where('slug', $newSlug)
            ->where('status', PageStatus::PUBLISHED->value)
            ->first(['id', 'slug']);
        if ($pageClaim !== null) {
            throw ValidationException::withMessages(['fields.slug' => "The slug [{$newSlug}] is already the current public slug of another Page."]);
        }

        $current = PageUrl::query()->where('slug', $newSlug)->lockForUpdate()->first();
        if ($current !== null && ((int) $current->page_id !== (int) $page->getKey() && $current->kind === 'current')) {
            throw ValidationException::withMessages(['fields.slug' => "The slug [{$newSlug}] is already the current URL of another Page."]);
        }
        if ($current !== null && (int) $current->page_id !== (int) $page->getKey()) {
            $current->delete();
        }

        $oldSlug = (string) $page->getRawOriginal('slug');
        if ($hadPublicPublication && $oldSlug !== $newSlug) {
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

    /**
     * @param  array<string, mixed>  $fields
     * @return array{title: string, slug: string, description: string, template: string}
     */
    private function validateCreationMetadata(array $fields): array
    {
        $unknown = array_diff(array_keys($fields), self::CREATION_FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields' => 'Unknown Page creation fields: ' . implode(', ', $unknown) . '.']);
        }

        foreach (self::CREATION_FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                throw ValidationException::withMessages(["fields.{$field}" => "The {$field} field is required."]);
            }
        }

        foreach (['title', 'description'] as $field) {
            if (! is_string($fields[$field]) || trim($fields[$field]) === '') {
                throw ValidationException::withMessages(["fields.{$field}" => "The {$field} field is required."]);
            }
        }

        if (mb_strlen($fields['title']) > 100) {
            throw ValidationException::withMessages(['fields.title' => 'The title must be 100 characters or fewer.']);
        }

        if (! is_string($fields['slug']) || mb_strlen($fields['slug']) > 100 || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $fields['slug'])) {
            throw ValidationException::withMessages(['fields.slug' => 'The slug must be a lowercase hyphen-separated URL segment.']);
        }

        if (! is_string($fields['template']) || ! $this->pages->has($fields['template'])) {
            throw ValidationException::withMessages(['fields.template' => 'Choose a registered compatible Page template.']);
        }

        return [
            'title' => $fields['title'],
            'slug' => $fields['slug'],
            'description' => $fields['description'],
            'template' => $fields['template'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function validateSectionCreationFields(array $fields): string
    {
        $unknown = array_diff(array_keys($fields), self::SECTION_CREATION_FIELDS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['section' => 'Unknown Section creation fields: ' . implode(', ', $unknown) . '.']);
        }
        if (! array_key_exists('title', $fields) || ! is_string($fields['title']) || trim($fields['title']) === '') {
            throw ValidationException::withMessages(['title' => 'The Section title is required.']);
        }
        if (mb_strlen($fields['title']) > 100) {
            throw ValidationException::withMessages(['title' => 'The Section title must be 100 characters or fewer.']);
        }

        return $fields['title'];
    }

    /** @param array<string, mixed> $content */
    private function normalizeServicesContent(array $content): array
    {
        if (! $this->sections->has('services')) {
            throw ValidationException::withMessages(['content' => 'The Services Section definition is unavailable.']);
        }

        $definition = $this->sections->get('services');
        $normalized = $definition::normalize($content);
        if ($definition::imageReferences($normalized) !== []) {
            throw ValidationException::withMessages(['content' => 'Only text-only Services content is supported here.']);
        }

        return $normalized;
    }

    private function assertServicesCreationPage(Page $page): void
    {
        $this->assertAdopted($page);
        $metadata = $this->draftMetadata($page);
        $composition = $this->pages->composition($metadata['template']);
        if (! in_array('services', $composition['allowed'], true)) {
            throw ValidationException::withMessages(['page' => 'The selected Page template does not allow Services Sections.']);
        }
    }

    private function authorizeCopySource(Section $source, int $expectedSourceVersion): void
    {
        $this->authorizeCopySourceAccess($source);

        $this->assertCopySource($source, $expectedSourceVersion);
    }

    private function authorizeCopySourceAccess(Section $source): void
    {
        $this->authorizeSection('viewAny', new Section);
        $this->authorizeSection('view', $source);
        $this->authorizeSection('useInPage', $source);
        $this->authorizeSection('replicate', $source);
    }

    private function assertCopySource(Section $source, int $expectedSourceVersion): void
    {
        if ($source->type !== 'services' || ! $this->sections->has('services') || $source->status !== SectionStatus::ACTIVE
            || $source->content_version === null || $source->content_version !== $expectedSourceVersion
            || ! is_array($source->published_content)) {
            throw new PageConflict('The published Services source changed or is unavailable. Refresh the source and review it again.');
        }
    }

    /** @param array<string, mixed> $content */
    private function assertSubmissionReview(string $submissionToken, int $pageId, Section $source, array $content): void
    {
        $row = DB::table(self::SUBMISSIONS_TABLE)->where('token', $submissionToken)->first();
        if ($row === null || $row->operation !== 'copy-section' || (string) $row->actor_id !== (string) auth()->id()
            || (string) $row->session_id !== (string) session()->getId() || (int) $row->page_id !== $pageId
            || (int) $row->source_id !== (int) $source->getKey() || ! is_string($row->review_fingerprint)
            || ! hash_equals($row->review_fingerprint, $this->sourceCopyFingerprint($source, $content))) {
            throw new PageConflict('The published Services source changed or was not reviewed. Refresh the source and review it again.');
        }
    }

    private function insertNewServicesSection(string $title, array $content): int
    {
        $now = now();
        $slug = 'section-' . Str::uuid()->toString();

        return (int) DB::table('sections')->insertGetId([
            'component_id' => null,
            'title' => $title,
            'description' => '',
            'slug' => $slug,
            'type' => 'services',
            'content' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => SectionStatus::ACTIVE->value,
            'draft_content' => json_encode($content, JSON_THROW_ON_ERROR),
            'published_content' => null,
            'content_version' => 1,
            'legacy_image_sources' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function appendDraftSection(Page $page, int $sectionId, int $expectedVersion): int
    {
        $order = (int) DB::table('page_section')
            ->where('page_id', $page->getKey())
            ->where('stage', 'draft')
            ->max('order_column');
        $now = now();
        DB::table('page_section')->insert([
            'page_id' => $page->getKey(),
            'section_id' => $sectionId,
            'order_column' => $order + 1,
            'stage' => 'draft',
            'occurrence_id' => (string) Str::uuid(),
            'slot' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pages')->where('id', $page->getKey())->update([
            'page_version' => $expectedVersion + 1,
            'updated_at' => $now,
        ]);

        return $expectedVersion + 1;
    }

    /** @return array<string, null> */
    private function emptyMetatags(): array
    {
        return array_fill_keys(self::METATAG_KEYS, null);
    }

    /** @param array<string, mixed> $content */
    private function sourceCopyFingerprint(Section $source, array $content): string
    {
        return hash('sha256', json_encode([
            'section_id' => (int) $source->getKey(),
            'type' => $source->type,
            'status' => $source->status->value,
            'content_version' => $source->content_version,
            'published_content' => $content,
            'media_ids' => $source->getMedia()->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'definition' => $this->sectionDefinitionFingerprint((string) $source->type),
        ], JSON_THROW_ON_ERROR));
    }

    private function sectionDefinitionFingerprint(string $type): string
    {
        $definition = $this->sections->get($type);
        $reflection = new \ReflectionClass($definition);
        $file = $reflection->getFileName();
        $source = is_string($file) && is_file($file) ? hash_file('sha256', $file) : false;

        return hash('sha256', json_encode([
            'class' => $definition,
            'key' => $definition::key(),
            'view' => $definition::view(),
            'source' => $source === false ? null : $source,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    private function submissionFingerprint(string $operation, ?int $pageId, ?int $sourceId, array $payload): string
    {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'page_id' => $pageId,
            'source_id' => $sourceId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }

    private function recordSubmissionReview(?string $token, int $pageId, int $sourceId, string $fingerprint): void
    {
        if ($token === null) {
            return;
        }

        DB::transaction(function () use ($token, $pageId, $sourceId, $fingerprint): void {
            $row = DB::table(self::SUBMISSIONS_TABLE)->where('token', $token)->lockForUpdate()->first();
            if ($row === null || $row->operation !== 'copy-section' || (string) $row->actor_id !== (string) auth()->id()
                || (string) $row->session_id !== (string) session()->getId() || (int) $row->page_id !== $pageId) {
                throw ValidationException::withMessages(['submission' => 'The submission token is invalid or expired. Start the action again.']);
            }
            if ($row->result !== null) {
                return;
            }

            DB::table(self::SUBMISSIONS_TABLE)->where('id', $row->id)->update([
                'source_id' => $sourceId,
                'review_fingerprint' => $fingerprint,
                'updated_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private function lockSubmission(?string $token, string $operation, ?int $pageId, ?int $sourceId, string $fingerprint): ?array
    {
        if ($token === null) {
            return null;
        }

        $row = DB::table(self::SUBMISSIONS_TABLE)->where('token', $token)->lockForUpdate()->first();
        if ($row === null || $row->operation !== $operation || (string) $row->actor_id !== (string) auth()->id()
            || (string) $row->session_id !== (string) session()->getId() || (int) ($row->page_id ?? 0) !== (int) ($pageId ?? 0)) {
            throw ValidationException::withMessages(['submission' => 'The submission token is invalid or expired. Start the action again.']);
        }
        if ($row->source_id !== null && (int) $row->source_id !== (int) ($sourceId ?? 0)) {
            throw ValidationException::withMessages(['submission' => 'The selected source changed. Start the action again.']);
        }
        if ($row->source_id === null && $sourceId !== null) {
            DB::table(self::SUBMISSIONS_TABLE)->where('id', $row->id)->update([
                'source_id' => $sourceId,
                'updated_at' => now(),
            ]);
        }
        if ($row->fingerprint !== null && ! hash_equals((string) $row->fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['submission' => 'This submission was already used for different values. Start a new action.']);
        }
        if ($row->result !== null) {
            $result = json_decode((string) $row->result, true);
            if (! is_array($result)) {
                throw new PageConflict('The previous creation result is unavailable. Start the action again.');
            }

            return $result;
        }

        DB::table(self::SUBMISSIONS_TABLE)->where('id', $row->id)->update([
            'fingerprint' => $fingerprint,
            'updated_at' => now(),
        ]);

        return null;
    }

    /** @param array<string, int> $result */
    private function completeSubmission(?string $token, array $result): void
    {
        if ($token === null) {
            return;
        }

        DB::table(self::SUBMISSIONS_TABLE)->where('token', $token)->update([
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function authorize(string $ability, Page | Section $record): void
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
        return 'phi-cms.reviews.pages.' . $page->getKey();
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
