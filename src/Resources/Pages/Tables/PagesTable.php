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
use JFA\PhiCmsCore\Resources\Pages\Pages\ManagePageSections;

class PagesTable
{
    public static function customActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('phi-cms-core::phi-cms-core.publish'))
                ->authorize('publish')
                ->action(function (Page $record): void {
                    $record->publish();
                })
                ->color('success')
                ->icon(Heroicon::OutlinedArrowSmallUp)
                ->hidden(function (Page $record): bool {
                    return $record->draft_fields !== null || $record->page_version !== null || $record->status->value === 'published';
                }),
            Action::make('unpublish')
                ->label(__('phi-cms-core::phi-cms-core.unpublish'))
                ->authorize('publish')
                ->action(function (Page $record): void {
                    $record->unpublish();
                })
                ->icon(Heroicon::OutlinedArrowSmallDown)
                ->color('warning')
                ->hidden(function (Page $record): bool {
                    return $record->draft_fields !== null || $record->page_version !== null || $record->status->value === 'draft';
                }),
            Action::make('preview')
                ->label(__('phi-cms-core::phi-cms-core.preview_page'))
                ->authorize('preview')
                ->icon(Heroicon::OutlinedEye)
                ->color('info')
                ->visible(fn (Page $record): bool => $record->page_version !== null)
                ->url(fn (Page $record): string => route('cms.page.preview', $record)),
            Action::make('manageSections')
                ->label(fn (Page $record): string => $record->page_version !== null
                    ? __('phi-cms-core::workflow.draft_sections')
                    : __('phi-cms-core::phi-cms-core.manage_sections'))
                ->icon(Heroicon::OutlinedRectangleGroup)
                ->color('accent')
                ->visible(function (Page $record): bool {
                    return $record->page_version !== null
                        ? ManagePageSections::canAccess(['record' => $record])
                        : $record->hasSections();
                })
                ->url(fn (Page $record): string => PageResource::getUrl('manageSections', ['record' => $record])),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->identifier('pages-with-statuses')
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
                TextColumn::make('sections.status')
                    ->label(__('phi-cms-core::workflow.section_status'))
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
                EditAction::make()
                    ->visible(fn (Page $record): bool => $record->page_version === null && $record->draft_fields === null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
