<?php

namespace JFA\PhiCmsCore\Resources\Menus\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('phi-cms-core::phi-cms-core.title'))
                    ->description(function ($record) {
                        return str()->limit($record->description, 100, '...');
                    })
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('phi-cms-core::phi-cms-core.slug'))
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
