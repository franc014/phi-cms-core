<?php

namespace JFA\PhiCmsCore\Livewire;

use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Forms\Components\Image;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
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

    /** @var array<string, mixed> */
    public array $data = [];

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
        $this->ensureContext();

        if ($sectionId !== null) {
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
            $this->dispatch('phi-section-editor-focus');

            return;
        }

        if ($this->pendingSectionId === null) {
            return;
        }

        if ($decision === 'save' && ! $this->persistDraft()) {
            return;
        }

        $this->loadSection($this->pendingSectionId);
        $this->dispatch('phi-section-editor-focus');
    }

    public function saveDraft(): void
    {
        try {
            $this->persistDraft();
        } finally {
            $this->dispatch('phi-section-editor-focus');
        }
    }

    public function reloadLatest(): void
    {
        $this->loadSection($this->selectedSection()->id);
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
            $this->publicationReview = $this->content()->review($section, $this->version);
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
            $this->version = $this->content()->publish($section, $this->version);
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
        $page = $this->pageId === null ? null : $this->content()->authorizePage(Page::findOrFail($this->pageId));
        $sections = $page?->sections()->where('status', SectionStatus::ACTIVE)->orderByPivot('order_column')->get() ?? collect();
        $section = $this->sectionId === null ? null : $this->selectedSection();

        return view('phi-cms-core::livewire.preview', [
            'page' => $page, 'sections' => $sections, 'preview' => true,
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
            $this->version = $this->content()->saveDraft($section, $state, $this->version);
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
        $this->version = $section->content_version;
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
            $page = $this->content()->authorizePage(Page::findOrFail($this->pageId));
            abort_unless($page->sections()->whereKey($sectionId)->where('status', SectionStatus::ACTIVE)->exists(), 403);
        }
        $this->content()->draft($section);

        return $section;
    }

    private function ensureContext(): void
    {
        request()->attributes->set('phi-cms.private', true);
        abort_unless(config('phi-cms.editing', false), 403);
        if ($this->pageId !== null) {
            $this->content()->authorizePage(Page::findOrFail($this->pageId));
            abort_unless($this->pageView !== null && view()->exists($this->pageView), 404);
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

    private function allows(string $ability, Section $section): bool
    {
        return auth()->user()?->fresh()?->can($ability, $section) ?? false;
    }

    private function content(): SectionContent
    {
        return app(SectionContent::class);
    }
}
