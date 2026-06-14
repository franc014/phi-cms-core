<?php

namespace JFA\PhiCmsCore\Resources\Pages\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Resources\Pages\PageResource;

class PagesTable
{
    public static function customActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('phi-cms-core::phi-cms-core.publish'))
                ->action(function ($record) {
                    $record->publish();
                })
                ->color('success')
                ->icon(Heroicon::OutlinedArrowSmallUp)
                ->hidden(function ($record) {
                    return $record->status->value === 'published';
                }),
            Action::make('unpublish')
                ->label(__('phi-cms-core::phi-cms-core.unpublish'))
                ->action(function ($record) {
                    $record->unpublish();
                })
                ->icon(Heroicon::OutlinedArrowSmallDown)
                ->color('warning')
                ->hidden(function ($record) {
                    return $record->status->value === 'draft';
                }),
            Action::make('manageSections')
                ->label(__('phi-cms-core::phi-cms-core.manage_sections'))
                ->icon(Heroicon::OutlinedRectangleGroup)
                ->color('accent')
                ->visible(function (Page $record) {
                    return $record->hasSections();
                })
                ->url(fn (Page $record): string => PageResource::getUrl('manageSections', ['record' => $record])),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('phi-cms-core::phi-cms-core.title'))
                    ->description(function ($record) {
                        return str()->limit($record->description, 80, '...');
                    })
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('phi-cms-core::phi-cms-core.status'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('sections.title')
                    ->label(__('phi-cms-core::phi-cms-core.sections'))
                    ->badge()
                    ->color('info')
                    ->searchable(),
                TextColumn::make('published_at')
                    ->label(__('phi-cms-core::phi-cms-core.published_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('phi-cms-core::phi-cms-core.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('phi-cms-core::phi-cms-core.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                ...self::customActions(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
