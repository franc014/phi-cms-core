<?php

namespace JFA\PhiCmsCore\Resources\Pages\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;

class ManagePageSections extends ManageRelatedRecords
{
    protected static string $resource = PageResource::class;

    protected static string $relationship = 'sections';

    protected static ?string $relatedResource = SectionResource::class;

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        if ($record instanceof Page && $record->page_version !== null) {
            return false;
        }

        return parent::canAccess($parameters);
    }

    public function table(Table $table): Table
    {
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
}
