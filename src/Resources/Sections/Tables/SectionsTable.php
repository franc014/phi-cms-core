<?php

namespace JFA\PhiCmsCore\Resources\Sections\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;

class SectionsTable
{
    public static function customActions(): array
    {
        return [
            Action::make('activate')
                ->label(__('phi-cms-core::phi-cms-core.activate'))
                ->authorize('update')
                ->action(function ($record) {
                    $record->activate();
                })
                ->color('success')
                ->icon(Heroicon::Check)
                ->hidden(function ($record) {
                    return $record->content_version !== null || $record->status->value === 'active';
                }),
            Action::make('deactivate')
                ->label(__('phi-cms-core::phi-cms-core.deactivate'))
                ->authorize('update')
                ->action(function ($record) {
                    $record->deactivate();
                })
                ->icon(Heroicon::NoSymbol)
                ->color('warning')
                ->hidden(function ($record) {
                    return $record->content_version !== null || $record->status->value === 'inactive';
                }),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('phi-cms-core::phi-cms-core.title'))
                    ->description(function ($record) {
                        return str()->limit($record->description, 100, '...');
                    })
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('phi-cms-core::phi-cms-core.status'))
                    ->badge()
                    ->searchable(),
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
                ReplicateAction::make()
                    ->visible(fn (Section $record): bool => $record->content_version === null
                        && (! config('phi-cms.editing', false) || $record->type !== 'services'))
                    ->authorize(fn (Section $record): bool => $record->content_version === null
                        && (! config('phi-cms.editing', false) || $record->type !== 'services')
                        && auth()->user()?->can('create', new Section) === true
                        && auth()->user()?->can('replicate', $record) === true)
                    ->beforeReplicaSaved(function (Section $replica): void {
                        $replica->title = $replica->title . '-Copy';
                        $replica->slug = $replica->slug . '-copy';
                    }),
                EditAction::make()
                    ->url(fn (Section $record): ?string => $record->content_version !== null ? SectionResource::getUrl('draft', ['record' => $record]) : null)
                    ->beforeFormFilled(fn (Section $record) => LegacySectionGuard::edit($record))
                    ->beforeFormValidated(fn (Section $record) => LegacySectionGuard::edit($record)),
                Action::make('viewDraft')->label(__('phi-cms-core::workflow.review'))
                    ->visible(function (Section $record): bool {
                        $actor = auth()->user()?->fresh();

                        return $record->content_version !== null && $actor !== null && $actor->can('viewDraft', $record) && ! $actor->can('update', $record);
                    })
                    ->url(fn (Section $record): string => SectionResource::getUrl('draft', ['record' => $record])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
