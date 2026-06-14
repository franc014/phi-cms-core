<?php

namespace JFA\PhiCmsCore\Resources\Pages\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;

class ManagePageSections extends ManageRelatedRecords
{
    protected static string $resource = PageResource::class;

    protected static string $relationship = 'sections';

    protected static ?string $relatedResource = SectionResource::class;

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
                EditAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
