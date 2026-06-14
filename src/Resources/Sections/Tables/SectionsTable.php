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
use Illuminate\Database\Eloquent\Model;

class SectionsTable
{
    public static function customActions(): array
    {
        return [
            Action::make('activate')
                ->label(__('phi-cms-core::phi-cms-core.activate'))
                ->action(function ($record) {
                    $record->activate();
                })
                ->color('success')
                ->icon(Heroicon::Check)
                ->hidden(function ($record) {
                    return $record->status->value === 'active';
                }),
            Action::make('deactivate')
                ->label(__('phi-cms-core::phi-cms-core.deactivate'))
                ->action(function ($record) {
                    $record->deactivate();
                })
                ->icon(Heroicon::NoSymbol)
                ->color('warning')
                ->hidden(function ($record) {
                    return $record->status->value === 'inactive';
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
                    ->beforeReplicaSaved(function (Model $replica): void {
                        $replica->title = $replica->title . '-Copy';
                        $replica->slug = $replica->slug . '-copy';
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
