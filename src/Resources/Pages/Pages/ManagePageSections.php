<?php

namespace JFA\PhiCmsCore\Resources\Pages\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\PageSection;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageConflict;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Pages\PageRegistry;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use Livewire\Attributes\Locked;

class ManagePageSections extends ManageRelatedRecords
{
    protected static string $resource = PageResource::class;

    protected static string $relationship = 'sections';

    protected static ?string $relatedResource = null;

    #[Locked]
    public ?int $pageVersion = null;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        $this->pageVersion = $this->getOwnerRecord()->page_version;
    }

    public static function getRelationshipTitle(): string
    {
        return SectionResource::getPluralModelLabel();
    }

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        if ($record instanceof Page) {
            $record = $record->fresh();
            if ($record?->page_version !== null) {
                $actor = auth()->user()?->fresh();

                return config('phi-cms.editing', false) && $actor?->can('viewAny', Page::class)
                    && $actor->can('preview', $record) && $actor->can('compose', $record);
            }
            if ($record?->draft_fields !== null) {
                return false;
            }
        }

        return parent::canAccess($parameters);
    }

    public function table(Table $table): Table
    {
        if ($this->getOwnerRecord()->page_version !== null) {
            return $table
                ->heading(__('phi-cms-core::workflow.draft_sections'))
                ->columns([
                    TextColumn::make('section.title')->label(__('phi-cms-core::workflow.section')),
                    TextColumn::make('section.status')->label(__('phi-cms-core::workflow.availability'))
                        ->badge(),
                    TextColumn::make('publication')->label(__('phi-cms-core::workflow.publication'))
                        ->state(fn (PageSection $record): string => __('phi-cms-core::workflow.' . $record->section->publicationState()))
                        ->badge(),
                ])
                ->reorderable('order_column')
                ->authorizeReorder(fn (): bool => static::canAccess(['record' => $this->getOwnerRecord()]))
                ->headerActions([
                    Action::make('createAndAddSection')
                        ->label(__('phi-cms-core::workflow.create_and_add_services'))
                        ->authorize(fn (): bool => $this->canCreateSection())
                        ->schema([
                            TextInput::make('title')->label(__('phi-cms-core::workflow.library_title'))->required()->maxLength(100),
                            ...$this->servicesFields(),
                            Hidden::make('_submission_token')
                                ->default(fn (): string => app(PageContent::class)->issueSubmissionToken('create-section', (int) $this->getOwnerRecord()->getKey())),
                        ])
                        ->action(function (array $data): void {
                            $submissionToken = $this->requiredSubmissionToken($data);
                            $page = $this->getOwnerRecord()->fresh();

                            try {
                                $result = app(PageContent::class)->createSection(
                                    $page,
                                    ['title' => $data['title'] ?? null],
                                    Arr::only($data, ['section_title', 'items']),
                                    (int) $this->pageVersion,
                                    $submissionToken,
                                );
                            } catch (PageConflict $exception) {
                                throw ValidationException::withMessages(['title' => $exception->getMessage()]);
                            }
                            $this->pageVersion = $result['page_version'];
                            $this->resetTable();
                        })
                        ->successNotificationTitle(__('phi-cms-core::workflow.notifications.section_created')),
                    Action::make('copyAndAddSection')
                        ->label(__('phi-cms-core::workflow.copy_published_services'))
                        ->authorize(fn (): bool => $this->canCreateSection())
                        ->schema([
                            Select::make('source_section_id')
                                ->label(__('phi-cms-core::workflow.published_services_source'))
                                ->options(fn (): array => collect(app(PageContent::class)->copyableSections($this->getOwnerRecord()))->pluck('title', 'id')->all())
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    if (! is_numeric($state)) {
                                        return;
                                    }
                                    $preview = app(PageContent::class)->copyableSectionPreview(
                                        $this->getOwnerRecord(),
                                        (int) $state,
                                        is_string($get('_submission_token')) ? $get('_submission_token') : null,
                                    );
                                    $source = Section::query()->find((int) $state);
                                    if ($source !== null && $get('_title_edited') !== true) {
                                        $copySuffix = ' — Copy';
                                        $set('title', mb_substr($source->title, 0, 100 - mb_strlen($copySuffix)) . $copySuffix);
                                    }
                                    $set('_source_version', $preview['version']);
                                }),
                            TextInput::make('title')
                                ->label(__('phi-cms-core::workflow.library_title'))
                                ->required()
                                ->maxLength(100)
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('_title_edited', true);
                                }),
                            Placeholder::make('published_snapshot')
                                ->label(__('phi-cms-core::workflow.published_snapshot'))
                                ->content(function (Get $get): string {
                                    if (! is_numeric($get('source_section_id'))) {
                                        return __('phi-cms-core::workflow.choose_published_source');
                                    }

                                    try {
                                        $preview = app(PageContent::class)->copyableSectionPreview(
                                            $this->getOwnerRecord(),
                                            (int) $get('source_section_id'),
                                            is_string($get('_submission_token')) ? $get('_submission_token') : null,
                                        );

                                        return json_encode($preview['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: __('phi-cms-core::workflow.published_content_empty');
                                    } catch (ValidationException | PageConflict) {
                                        return __('phi-cms-core::workflow.published_content_unavailable');
                                    }
                                }),
                            Hidden::make('_title_edited')->default(false)->dehydrated(false),
                            Hidden::make('_source_version')->default(null),
                            Hidden::make('_submission_token')
                                ->default(fn (): string => app(PageContent::class)->issueSubmissionToken('copy-section', (int) $this->getOwnerRecord()->getKey())),
                        ])
                        ->action(function (array $data): void {
                            $submissionToken = $this->requiredSubmissionToken($data);
                            $page = $this->getOwnerRecord()->fresh();
                            $source = Section::query()->findOrFail((int) $data['source_section_id']);
                            $sourceVersion = filter_var($data['_source_version'] ?? null, FILTER_VALIDATE_INT);
                            if ($sourceVersion === false || $sourceVersion < 1) {
                                throw ValidationException::withMessages(['source_section_id' => 'Review the published source before copying it.']);
                            }

                            try {
                                $result = app(PageContent::class)->copySection(
                                    $page,
                                    $source,
                                    ['title' => $data['title'] ?? null],
                                    (int) $this->pageVersion,
                                    $sourceVersion,
                                    $submissionToken,
                                );
                            } catch (PageConflict $exception) {
                                throw ValidationException::withMessages(['source_section_id' => $exception->getMessage()]);
                            }
                            $this->pageVersion = $result['page_version'];
                            $this->resetTable();
                        })
                        ->successNotificationTitle(__('phi-cms-core::workflow.notifications.section_copied')),
                    Action::make('addSection')->label(__('phi-cms-core::workflow.add_existing_section'))
                        ->schema([Select::make('section_id')->label(__('phi-cms-core::workflow.section'))
                            ->helperText(__('phi-cms-core::workflow.shared_section_help'))
                            ->required()
                            ->searchable()
                            ->options(fn (): array => collect(app(PageContent::class)->selectableSectionChoices($this->getOwnerRecord()))
                                ->mapWithKeys(function (array $choice): array {
                                    $section = $choice['section'];
                                    if (! $section instanceof Section || ! is_string($choice['label'] ?? null)) {
                                        return [];
                                    }

                                    return [$section->getKey() => $choice['label']];
                                })
                                ->all())])
                        ->action(function (array $data): void {
                            $rows = $this->draftRows();
                            $rows[] = ['pivot_id' => null, 'section_id' => (int) $data['section_id'], 'slot' => null];
                            $this->saveRows($rows);
                        })
                        ->successNotificationTitle(__('phi-cms-core::workflow.notifications.section_assigned')),
                ])
                ->recordActions([
                    Action::make('removeSection')->label(__('phi-cms-core::workflow.remove_assignment'))
                        ->action(function (PageSection $record): void {
                            $rows = array_values(array_filter($this->draftRows(), fn (array $row): bool => $row['pivot_id'] !== $record->getKey()));
                            $this->saveRows($rows);
                        })
                        ->successNotificationTitle(__('phi-cms-core::workflow.notifications.section_removed')),
                    Action::make('editSection')->label(__('phi-cms-core::workflow.edit_section_content'))
                        ->visible(fn (PageSection $record): bool => $record->section->content_version !== null && (auth()->user()?->fresh()?->can('update', $record->section) ?? false))
                        ->url(fn (PageSection $record): string => SectionResource::getUrl('draft', ['record' => $record->section_id])),
                ])->toolbarActions([]);
        }

        SectionResource::configureTable($table);

        return $table
            ->reorderRecordsTriggerAction(
                fn (Action $action, bool $isReordering) => $action
                    ->button()
                    ->icon($isReordering ? Heroicon::OutlinedXCircle : Heroicon::OutlinedArrowsUpDown)
                    ->label($isReordering ? __('phi-cms-core::workflow.close_reordering') : __('phi-cms-core::workflow.reorder_sections')),
            )
            ->modifyQueryUsing(function (Builder $query) {
                return $query->orderBy('order_column');
            })
            ->reorderable('order_column', function ($table) {
                return $table->getQuery()->count() > 1;
            })
            ->recordActions([
                EditAction::make()
                    ->url(fn (Section $record): ?string => $record->content_version !== null ? SectionResource::getUrl('draft', ['record' => $record]) : null)
                    ->beforeFormFilled(fn (Section $record) => LegacySectionGuard::edit($record))
                    ->beforeFormValidated(fn (Section $record) => LegacySectionGuard::edit($record)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->databaseTransaction()
                    ->before(function (array $data): void {
                        if (config('phi-cms.editing', false) && ($data['type'] ?? null) === 'services') {
                            throw ValidationException::withMessages([
                                'type' => __('phi-cms-core::phi-cms-core.services_creation_workflow_required'),
                            ]);
                        }
                    })
                    ->beforeFormValidated(function (): void {
                        /** @var Page $page */
                        $page = $this->getOwnerRecord();
                        LegacySectionGuard::assertLegacyPlacementAllowed($page);
                    }),
            ]);
    }

    public function reorderTable(array $order, int | string | null $draggedRecordKey = null): void
    {
        if ($this->getOwnerRecord()->fresh()->page_version !== null) {
            $rows = collect($this->draftRows())->keyBy('pivot_id');
            $ids = array_map(intval(...), $order);
            if (count($ids) !== $rows->count() || count(array_unique($ids)) !== count($ids) || array_diff($ids, $rows->keys()->all()) !== []) {
                throw ValidationException::withMessages(['sections' => 'Reorder must contain every current draft assignment exactly once. Reload latest.']);
            }
            $this->saveRows(array_map(fn (int $id): array => $rows[$id], $ids));
            Notification::make()
                ->title(__('phi-cms-core::workflow.notifications.sections_reordered'))
                ->success()
                ->send();

            return;
        }

        DB::transaction(function () use ($order, $draggedRecordKey): void {
            /** @var Page $page */
            $page = $this->getOwnerRecord();
            $sections = $page->sections()->orderBy('sections.id')->lockForUpdate()->get();
            foreach ($sections as $section) {
                LegacySectionGuard::edit($section);
            }
            parent::reorderTable($order, $draggedRecordKey);
        });
    }

    public function getRelationship(): Relation | Builder
    {
        $page = $this->getOwnerRecord();

        return $page->page_version !== null ? $page->draftSectionAssignments() : $page->sections();
    }

    public function form(Schema $schema): Schema
    {
        return $this->getOwnerRecord()->page_version !== null ? $schema : SectionResource::form($schema);
    }

    /** @return list<array{pivot_id: int, section_id: int, slot: int|null}> */
    private function draftRows(): array
    {
        abort_unless(static::canAccess(['record' => $this->getOwnerRecord()]), 403);

        return $this->getOwnerRecord()->draftSectionAssignments()->get()
            ->map(fn (PageSection $row): array => ['pivot_id' => (int) $row->id, 'section_id' => (int) $row->section_id, 'slot' => $row->slot])->all();
    }

    /** @param list<array{pivot_id: int|null, section_id: int, slot: int|null}> $rows */
    private function saveRows(array $rows): void
    {
        try {
            $this->pageVersion = app(PageContent::class)->saveDraftSections($this->getOwnerRecord()->fresh(), $rows, (int) $this->pageVersion);
            $this->resetTable();
        } catch (PageConflict $exception) {
            throw ValidationException::withMessages(['sections' => $exception->getMessage()]);
        }
    }

    /** @return list<Component> */
    private function servicesFields(): array
    {
        return app(SectionRegistry::class)->get('services')::fields();
    }

    private function canCreateSection(): bool
    {
        return static::canCreateServicesSection($this->getOwnerRecord());
    }

    public static function canCreateServicesSection(Page $page): bool
    {
        $page = $page->fresh();
        $actor = auth()->user()?->fresh();
        if ($page?->page_version === null || ! is_array($page->draft_fields)) {
            return false;
        }
        $composition = app(PageRegistry::class)->composition($page->draft_fields['template']);

        return config('phi-cms.editing', false)
            && $composition['mode'] === 'ordered'
            && in_array('services', $composition['allowed'], true)
            && $actor !== null
            && $actor->can('preview', $page)
            && $actor->can('compose', $page)
            && $actor->can('create', new Section);
    }

    /** @param array<string, mixed> $data */
    private function requiredSubmissionToken(array $data): string
    {
        $token = $data['_submission_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['_submission_token' => 'Reopen the creation action and try again.']);
        }

        return $token;
    }
}
