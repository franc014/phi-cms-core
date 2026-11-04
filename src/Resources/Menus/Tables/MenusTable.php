<?php

namespace JFA\PhiCmsCore\Resources\Menus\Tables;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Models\Menu;
use JFA\PhiCmsCore\Resources\Menus\MenuResource;

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
                Action::make('manageMenu')
                    ->label('Manage')
                    ->url(fn (Menu $record): string => MenuResource::getUrl('edit', ['record' => $record]))
                    ->visible(function (Menu $record): bool {
                        $actor = auth()->user()?->fresh();
                        if ($actor === null) {
                            return false;
                        }

                        return $record->menu_version === null
                            ? $actor->can('update', $record)
                            : $actor->can('viewDraft', $record)
                                && ($actor->can('update', $record) || $actor->can('publish', $record));
                    }),
            ])
            ->toolbarActions([]);
    }
}
