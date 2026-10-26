<?php

namespace JFA\PhiCmsCore\Livewire;

use Filament\Forms\FormsComponent;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Forms\Components\Image;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageConflict;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Sections\SectionConflict;
use JFA\PhiCmsCore\Sections\SectionContent;
use JFA\PhiCmsCore\Sections\SectionImageContext;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/** @property-read Schema $form */
class Preview extends FormsComponent
{
    use WithFileUploads {
        _finishUpload as protected finishNativeUpload;
        _removeUpload as protected removeNativeUpload;
    }

    #[Locked]
    public ?string $uploadContext = null;

    #[Renderless]
    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        abort_unless(is_string($name) && $isMultiple === false, 403);
        $this->authorizeImageField($name);
        abort_if($this->uploadContext === null, 403);
        $ticket = app(SectionTemporaryUploads::class)->issue($this->getId(), $this->uploadContext, $name);
        $url = URL::temporarySignedRoute('phi-cms.images.temporary', now()->addMinutes(5), ['ticket' => $ticket], absolute: false);
        $this->dispatch('upload:generatedSignedUrl', name: $name, url: $url)->self();
    }

    public function _finishUpload(mixed $name, mixed $tmpPath, mixed $isMultiple, mixed $append = true): void
    {
        abort_unless(is_string($name) && is_array($tmpPath) && count($tmpPath) === 1 && is_string($tmpPath[0] ?? null) && $isMultiple === false, 403);
        $this->authorizeImageField($name);
        foreach ($tmpPath as $signed) {
            $path = TemporaryUploadedFile::extractPathFromSignedPath($signed);
            abort_unless(is_string($path), 403);
            app(SectionTemporaryUploads::class)->file($path, true, $this->getId(), $name);
        }
        $this->finishNativeUpload($name, $tmpPath, $isMultiple, $append);
    }

    public function _removeUpload(mixed $name, mixed $tmpFilename): void
    {
        abort_unless(is_string($name) && is_string($tmpFilename), 403);
        $this->authorizeImageField($name);
        app(SectionTemporaryUploads::class)->file($tmpFilename, true, $this->getId(), $name);
        $this->removeNativeUpload($name, $tmpFilename);
    }

    protected function cleanupOldUploads(): void {}

    private function authorizeImageField(string $name): void
    {
        $this->selectedSection();
        app(SectionImageContext::class)->authorizeUpload();
        $field = $this->form->getComponentByStatePath($name, withAbsoluteStatePath: true);
        if ($field === null && Str::isUuid(Str::afterLast($name, '.'))) {
            $field = $this->form->getComponentByStatePath(Str::beforeLast($name, '.'), withAbsoluteStatePath: true);
        }
        abort_unless($field instanceof Image, 403);
    }

    #[Locked]
    public ?int $pageId = null;

    #[Locked]
    public ?string $pageView = null;

    #[Locked]
    public ?int $sectionId = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public ?int $sectionVersion = null;

    #[Locked]
    public bool $pageMode = false;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $pageData = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $pageBaseline = [];

    /** @var list<array{pivot_id: int|null, section_id: int, slot: int|null}> */
    public array $pageRows = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $pagePublicationReview = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $baseline = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $publicationReview = null;

    #[Locked]
    public ?int $pendingSectionId = null;

    public function mount(?int $pageId = null, ?string $pageView = null, ?int $sectionId = null): void
    {
        $this->pageId = $pageId;
        $this->pageView = $pageView;
        $this->sectionId = $sectionId;
        $this->pageMode = $pageId !== null && Page::query()->whereKey($pageId)->value('page_version') !== null;
        $this->ensureContext();

        if ($this->pageMode) {
            $this->loadPage();
        } elseif ($sectionId !== null) {
            $this->loadSection($sectionId);
        }
    }

    public function hydrate(): void
    {
        $this->ensureContext();
    }

    public function selectSection(int $sectionId): void
    {
        abort_if($this->pageId === null, 403);
        if ($this->pageMode) {
            $section = $this->authorizedSection($sectionId);
            abort_if($section->content_version === null, 403);
        }
        $this->authorizedSection($sectionId);

        if ($this->sectionId !== $sectionId && $this->isDirty()) {
            $this->pendingSectionId = $sectionId;

            return;
        }

        if ($this->sectionId !== $sectionId) {
            $this->loadSection($sectionId);
        }
    }

    public function resolveSwitch(string $decision): void
    {
        $this->ensureContext();
        abort_unless(in_array($decision, ['save', 'discard', 'cancel'], true), 422);

        if ($decision === 'cancel') {
            $this->pendingSectionId = null;
            $this->focusActiveEditor();

            return;
        }

        if ($this->pendingSectionId === null) {
            return;
        }

        $pendingSectionId = $this->pendingSectionId;

        if ($decision === 'discard' && $this->pageIsDirty()) {
            $this->pageData = $this->pageBaseline;
            $this->resetErrorBag();
        }

        if ($decision === 'save') {
            try {
                $saved = $this->savePendingDraft();
            } catch (ValidationException $exception) {
                $this->focusActiveEditor();

                throw $exception;
            }

            if (! $saved) {
                $this->focusActiveEditor();

                return;
            }
        }

        $this->loadSection($pendingSectionId);
        $this->focusSectionEditor();
    }

    public function saveDraft(): void
    {
        if ($this->pageMode && $this->sectionId === null) {
            $this->savePageDraft();

            return;
        }

        try {
            $this->persistDraft();
        } finally {
            $this->focusSectionEditor();
        }
    }

    public function reloadLatest(): void
    {
        if ($this->pageMode) {
            if ($this->sectionId !== null) {
                $this->loadSection($this->sectionId);

                return;
            }

            $this->reloadPageDraft();

            return;
        }

        $this->loadSection($this->selectedSection()->id);
    }

    public function reloadPageDraft(): void
    {
        abort_unless($this->pageMode, 403);
        $this->loadPage();
        $this->resetErrorBag();
    }

    public function savePageDraft(): void
    {
        try {
            $this->persistPageDraft();
        } finally {
            $this->focusPageEditor();
        }
    }

    private function persistPageDraft(): bool
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);

        try {
            $this->version = app(PageContent::class)->saveDraft(Page::findOrFail($this->pageId), $this->pageData, (int) $this->version);
            $this->pageBaseline = $this->pageData;
            $this->pagePublicationReview = null;
            $this->resetErrorBag();

            return true;
        } catch (PageConflict $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', $exception->getMessage());

            return false;
        }
    }

    private function savePendingDraft(): bool
    {
        if (! $this->pageMode) {
            return $this->persistDraft();
        }

        if ($this->pageIsDirty() && ! $this->persistPageDraft()) {
            return false;
        }

        return ! $this->sectionIsDirty() || $this->persistDraft();
    }

    public function savePageComposition(): void
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);

        try {
            $this->version = app(PageContent::class)->saveDraftSections(Page::findOrFail($this->pageId), $this->pageRows, (int) $this->version);
            $this->pagePublicationReview = null;
            $this->refreshPageRows();
            if ($this->sectionId !== null && ! collect($this->pageRows)->contains(fn (array $row): bool => (int) $row['section_id'] === $this->sectionId)) {
                $this->deselectSection();
            }
            $this->resetErrorBag();
        } catch (PageConflict $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
        }
    }

    public function addPageSection(int $sectionId): void
    {
        abort_unless($this->pageMode, 403);
        $this->pageRows[] = ['pivot_id' => null, 'section_id' => $sectionId, 'slot' => null];
        $this->savePageComposition();
    }

    public function restoreLegacyPageSection(int $sectionId): void
    {
        abort_unless($this->pageMode, 403);
        $this->pageRows[] = ['pivot_id' => null, 'section_id' => $sectionId, 'slot' => null];
        $this->savePageComposition();
    }

    public function removePageSection(int $pivotId): void
    {
        abort_unless($this->pageMode, 403);
        $this->pageRows = array_values(array_filter($this->pageRows, fn (array $row): bool => (int) $row['pivot_id'] !== $pivotId));
        $this->savePageComposition();
    }

    public function movePageSection(int $pivotId, string $direction): void
    {
        abort_unless($this->pageMode && in_array($direction, ['up', 'down'], true), 403);
        $index = collect($this->pageRows)->search(fn (array $row): bool => (int) $row['pivot_id'] === $pivotId);
        if ($index === false) {
            abort(404);
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($this->pageRows)) {
            return;
        }
        [$this->pageRows[$index], $this->pageRows[$target]] = [$this->pageRows[$target], $this->pageRows[$index]];
        $this->savePageComposition();
    }

    public function reviewPagePublication(): void
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);
        if ($this->isDirty()) {
            $this->addError('workflow', __('phi-cms-core::workflow.unsaved'));

            return;
        }

        try {
            $this->pagePublicationReview = app(PageContent::class)->review(Page::findOrFail($this->pageId), (int) $this->version);
            $this->resetErrorBag('workflow');
        } catch (PageConflict $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', $exception->getMessage());
        }
    }

    private function refreshPagePublicationReview(): void
    {
        if ($this->pagePublicationReview === null || $this->pageId === null) {
            return;
        }

        try {
            $this->pagePublicationReview = app(PageContent::class)->review(Page::findOrFail($this->pageId), (int) $this->version);
        } catch (PageConflict $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', $exception->getMessage() ?: 'The Page changed after review. Reload the Page draft and review again.');
        } catch (ValidationException $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
        } catch (AuthorizationException) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', 'Your Page publication permission changed. Review the Page again if access is restored.');
        }
    }

    public function publishPage(): void
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);
        if ($this->isDirty() || $this->pagePublicationReview === null || ! is_string($this->pagePublicationReview['token'] ?? null)) {
            $this->addError('workflow', __('phi-cms-core::workflow.review_required'));

            return;
        }

        try {
            $this->version = app(PageContent::class)->publish(Page::findOrFail($this->pageId), (int) $this->version, $this->pagePublicationReview['token']);
            $this->pagePublicationReview = null;
            $this->loadPage();
            $this->resetErrorBag();
        } catch (PageConflict $exception) {
            $this->pagePublicationReview = null;
            $this->addError('workflow', $exception->getMessage());
        }
    }

    public function unpublishPage(): void
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);

        try {
            $this->version = app(PageContent::class)->unpublish(Page::findOrFail($this->pageId), (int) $this->version);
            $this->loadPage();
            $this->resetErrorBag();
        } catch (PageConflict $exception) {
            $this->addError('workflow', $exception->getMessage());
        }
    }

    public function deletePage(): void
    {
        abort_unless($this->pageMode && $this->pageId !== null, 403);

        try {
            $path = app(PageContent::class)->delete(Page::findOrFail($this->pageId), (int) $this->version);
        } catch (PageConflict $exception) {
            $this->addError('workflow', $exception->getMessage());

            return;
        }

        Notification::make()
            ->title('Page deleted')
            ->body("A private recovery export was written to [{$path}].")
            ->success()
            ->send();
        $this->redirect(PageResource::getUrl('index'));
    }

    public function reviewPublication(): void
    {
        $section = $this->selectedSection();
        $this->content()->authorize('publish', $section);

        if ($this->isDirty()) {
            $this->addError('workflow', __('phi-cms-core::workflow.unsaved'));

            return;
        }

        try {
            $this->publicationReview = $this->content()->review($section, (int) $this->sectionVersion);
            $this->resetErrorBag('workflow');
        } catch (SectionConflict $exception) {
            $this->publicationReview = null;
            $this->addError('workflow', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->publicationReview = null;

            throw ValidationException::withMessages($this->formValidationErrors($exception));
        }
    }

    public function publishSection(): void
    {
        $section = $this->selectedSection();
        $this->content()->authorize('publish', $section);

        if ($this->isDirty() || $this->publicationReview === null) {
            $this->addError('workflow', __('phi-cms-core::workflow.review_required'));

            return;
        }

        try {
            $this->sectionVersion = $this->content()->publish($section, (int) $this->sectionVersion);
            $this->publicationReview = null;
            $this->resetErrorBag();
        } catch (SectionConflict $exception) {
            $this->publicationReview = null;
            $this->addError('workflow', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->publicationReview = null;

            throw ValidationException::withMessages($this->formValidationErrors($exception));
        }
    }

    public function form(Schema $schema): Schema
    {
        $fields = $this->sectionId === null ? [] : app(SectionRegistry::class)->get($this->selectedSection()->type)::fields();

        return $schema->components($fields)->statePath('data');
    }

    public function render(): View
    {
        $this->ensureContext();
        if ($this->pageMode) {
            $this->refreshPagePublicationReview();
            $page = app(PageContent::class)->previewPage(Page::findOrFail($this->pageId));
            $sections = collect(app(PageContent::class)->previewSections($page));
            $selectedSection = $this->sectionId === null ? null : $this->selectedSection();

            return view('phi-cms-core::livewire.preview', [
                'page' => $page,
                'sections' => $sections,
                'preview' => true,
                'selectedSection' => $selectedSection,
                'canUpdate' => $selectedSection !== null && $this->allows('update', $selectedSection),
                'canPublish' => $selectedSection !== null && $this->allows('publish', $selectedSection),
                'pageTemplateView' => app(PageContent::class)->templateView($page, true),
                'pageData' => $this->pageData,
                'pageRows' => $this->pageRows,
                'pageReview' => $this->pagePublicationReview,
                'canUpdatePage' => auth()->user()?->fresh()?->can('update', $page) ?? false,
                'canComposePage' => auth()->user()?->fresh()?->can('compose', $page) ?? false,
                'canPublishPage' => auth()->user()?->fresh()?->can('publish', $page) ?? false,
                'canDeletePage' => auth()->user()?->fresh()?->can('delete', $page) ?? false,
                'selectableSections' => app(PageContent::class)->selectableSections($page),
                'restorableLegacySections' => app(PageContent::class)->restorableLegacySections($page),
            ]);
        }
        $page = $this->pageId === null ? null : $this->content()->authorizePage(Page::findOrFail($this->pageId));
        $sections = $page?->sections()->where('status', SectionStatus::ACTIVE)->orderByPivot('order_column')->get() ?? collect();
        $section = $this->sectionId === null ? null : $this->selectedSection();

        return view('phi-cms-core::livewire.preview', [
            'page' => $page, 'sections' => $sections, 'preview' => true,
            'pageTemplateView' => $this->pageView,
            'selectedSection' => $section,
            'canUpdate' => $section !== null && $this->allows('update', $section),
            'canPublish' => $section !== null && $this->allows('publish', $section),
        ]);
    }

    private function persistDraft(): bool
    {
        $section = $this->selectedSection();
        $this->content()->authorize('update', $section);

        try {
            app(SectionRegistry::class)->get($section->type)::normalize($this->canonicalInput());
            $state = $this->form->getState();
            $this->sectionVersion = $this->content()->saveDraft($section, $state, (int) $this->sectionVersion);
            $this->baseline = app(SectionRegistry::class)->get($section->type)::normalize($state);
            $this->publicationReview = null;
            $this->resetErrorBag();

            return true;
        } catch (SectionConflict $exception) {
            $this->addError('workflow', $exception->getMessage());

            return false;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->formValidationErrors($exception));
        }
    }

    /**
     * The native form keeps associative repeater keys while validation runs on
     * the canonical list snapshot. Translate both the content prefix and those
     * positional keys so refusals land on the image control the editor used.
     *
     * @return array<string, array<int, string>>
     */
    private function formValidationErrors(ValidationException $exception): array
    {
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $path = preg_replace('/^content(?=\.|$)/', 'data', $key);
            $messages[$this->formStatePath($path)] = $errors;
        }

        return $messages;
    }

    private function formStatePath(string $path): string
    {
        $segments = explode('.', $path);
        $state = $this->data;
        $mapped = [array_shift($segments)];

        foreach ($segments as $index => $segment) {
            if (! is_array($state)) {
                return implode('.', [...$mapped, ...array_slice($segments, $index)]);
            }

            if (array_key_exists($segment, $state)) {
                $state = $state[$segment];
                $mapped[] = $segment;

                continue;
            }

            if (ctype_digit($segment) && ! array_is_list($state)) {
                $key = array_keys($state)[(int) $segment] ?? null;

                if ($key !== null) {
                    $state = $state[$key];
                    $mapped[] = (string) $key;

                    continue;
                }
            }

            $mapped[] = $segment;
            $state = null;
        }

        return implode('.', $mapped);
    }

    private function loadSection(int $sectionId): void
    {
        $section = $this->authorizedSection($sectionId);
        app(SectionImageContext::class)->set(
            $section,
            $this->pageId === null ? null : Page::findOrFail($this->pageId),
            preview: true,
        );
        $this->sectionId = $sectionId;
        $this->sectionVersion = $section->content_version;
        if (! $this->pageMode) {
            $this->version = $section->content_version;
        }
        $this->uploadContext = app(SectionTemporaryUploads::class)->activate($this->getId());
        $this->baseline = $this->content()->draft($section);
        $this->cacheSchema('form', null);
        $this->form->fill($this->baseline);
        $this->pendingSectionId = null;
        $this->publicationReview = null;
        $this->resetErrorBag();
    }

    private function isDirty(): bool
    {
        return $this->pageIsDirty() || $this->sectionIsDirty();
    }

    private function pageIsDirty(): bool
    {
        return $this->pageMode && $this->pageData !== $this->pageBaseline;
    }

    private function sectionIsDirty(): bool
    {
        if ($this->sectionId === null) {
            return false;
        }

        try {
            return app(SectionRegistry::class)->get($this->selectedSection()->type)::normalize($this->canonicalInput()) !== $this->baseline;
        } catch (ValidationException) {
            return true;
        }
    }

    private function selectedSection(): Section
    {
        abort_if($this->sectionId === null, 403);

        return $this->authorizedSection($this->sectionId);
    }

    /** @return array<string, mixed> */
    private function canonicalInput(): array
    {
        $data = $this->data;
        foreach ($this->form->getFlatComponents(withActions: false, withHidden: true) as $field) {
            if (! $field instanceof Image) {
                continue;
            }
            $path = preg_replace('/^data\./', '', $field->getStatePath());
            $state = data_get($data, $path);
            if (is_array($state)) {
                $state = array_values($state)[0] ?? null;
            }
            if ($state instanceof TemporaryUploadedFile) {
                $state = 'pending:' . $state->getFilename();
            }
            data_set($data, $path, $state);
        }

        return $data;
    }

    private function authorizedSection(int $sectionId): Section
    {
        $section = Section::findOrFail($sectionId);
        if ($this->pageId !== null) {
            if ($this->pageMode) {
                $page = app(PageContent::class)->authorizePreview(Page::findOrFail($this->pageId));
                abort_unless($page->draftSections()->whereKey($sectionId)->where('status', SectionStatus::ACTIVE)->exists(), 403);
            } else {
                $page = $this->content()->authorizePage(Page::findOrFail($this->pageId));
                abort_unless($page->sections()->whereKey($sectionId)->where('status', SectionStatus::ACTIVE)->exists(), 403);
            }
        }
        if ($this->pageMode && $section->content_version === null) {
            return $section;
        }
        $this->content()->draft($section);

        return $section;
    }

    private function ensureContext(): void
    {
        request()->attributes->set('phi-cms.private', true);
        abort_unless(config('phi-cms.editing', false), 403);
        if ($this->pageId !== null) {
            if ($this->pageMode) {
                app(PageContent::class)->authorizePreview(Page::findOrFail($this->pageId));
            } else {
                $this->content()->authorizePage(Page::findOrFail($this->pageId));
                abort_unless($this->pageView !== null && view()->exists($this->pageView), 404);
            }
        } else {
            abort_if($this->sectionId === null || $this->pageView !== null, 403);
        }

        if ($this->sectionId !== null) {
            $section = $this->authorizedSection($this->sectionId);
            app(SectionImageContext::class)->set(
                $section,
                $this->pageId === null ? null : Page::findOrFail($this->pageId),
                preview: true,
            );
        }
    }

    private function loadPage(): void
    {
        abort_unless($this->pageId !== null, 403);
        $page = Page::findOrFail($this->pageId);
        $metadata = app(PageContent::class)->preview($page);
        $this->version = $metadata['version'];
        $this->pageData = [
            'title' => $metadata['title'],
            'slug' => $metadata['slug'],
            'description' => $metadata['description'],
            'template' => $metadata['template'],
            'metatags' => $metadata['metatags'],
        ];
        $this->pageBaseline = $this->pageData;
        $this->refreshPageRows();
        $this->pagePublicationReview = null;
        $this->pendingSectionId = null;
        if ($this->sectionId !== null) {
            $this->deselectSection();
        }
    }

    private function refreshPageRows(): void
    {
        abort_unless($this->pageId !== null, 403);
        $this->pageRows = collect(app(PageContent::class)->previewSections(Page::findOrFail($this->pageId)))
            ->map(fn (Section $section): array => [
                'pivot_id' => (int) $section->pivot->id,
                'section_id' => (int) $section->getKey(),
                'slot' => $section->pivot->slot === null ? null : (int) $section->pivot->slot,
            ])->values()->all();
    }

    private function deselectSection(): void
    {
        $this->sectionId = null;
        $this->sectionVersion = null;
        $this->data = [];
        $this->baseline = [];
        $this->publicationReview = null;
        $this->cacheSchema('form', null);
    }

    private function allows(string $ability, Section $section): bool
    {
        return auth()->user()?->fresh()?->can($ability, $section) ?? false;
    }

    private function focusActiveEditor(): void
    {
        if ($this->sectionId === null) {
            $this->focusPageEditor();

            return;
        }

        $this->focusSectionEditor();
    }

    private function focusPageEditor(): void
    {
        $this->dispatch('phi-page-editor-focus');
    }

    private function focusSectionEditor(): void
    {
        $this->dispatch('phi-section-editor-focus');
    }

    private function content(): SectionContent
    {
        return app(SectionContent::class);
    }
}
