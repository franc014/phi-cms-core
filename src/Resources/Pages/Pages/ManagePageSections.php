<?php

namespace JFA\PhiCmsCore\Resources\Pages\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\PageSection;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageConflict;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
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
                ->heading('Draft sections')
                ->columns([
                    TextColumn::make('section.title')->label('Section'),
                    TextColumn::make('section.status')->label('Availability'),
                ])
                ->reorderable('order_column')
                ->authorizeReorder(fn (): bool => static::canAccess(['record' => $this->getOwnerRecord()]))
                ->headerActions([
                    Action::make('addSection')->label('Add existing Section')
                        ->schema([Select::make('section_id')->label('Section')->required()
                            ->options(fn (): array => collect(app(PageContent::class)->selectableSections($this->getOwnerRecord()))->pluck('title', 'id')->all())])
                        ->action(function (array $data): void {
                            $rows = $this->draftRows();
                            $rows[] = ['pivot_id' => null, 'section_id' => (int) $data['section_id'], 'slot' => null];
                            $this->saveRows($rows);
                        }),
                ])
                ->recordActions([
                    Action::make('removeSection')->label('Remove assignment')
                        ->action(function (PageSection $record): void {
                            $rows = array_values(array_filter($this->draftRows(), fn (array $row): bool => $row['pivot_id'] !== $record->getKey()));
                            $this->saveRows($rows);
                        }),
                    Action::make('editSection')->label('Edit Section content')
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
                    ->label($isReordering ? 'Close reordering' : 'Reorder sections'),
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
}
