<?php

namespace JFA\PhiCmsCore\Resources\Menus\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('phi-cms-core::phi-cms-core.menu_item'))
                    ->collapsible()
                    ->schema([
                        TextInput::make('label')
                            ->label(__('phi-cms-core::phi-cms-core.label'))
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                $set('slug', Str::slug($state));
                                $set('url', '/' . Str::slug($state));
                            }),
                        TextInput::make('slug')
                            ->label(__('phi-cms-core::phi-cms-core.slug'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('url')
                            ->label(__('phi-cms-core::phi-cms-core.url'))
                            ->required(),
                    ]),

                Section::make(__('phi-cms-core::phi-cms-core.sub_menu_items'))
                    ->description(__('phi-cms-core::phi-cms-core.the_subitems_of_the_menu_item'))
                    ->collapsible()
                    ->schema([
                        Repeater::make('items')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                TextInput::make('label')
                                    ->label(__('phi-cms-core::phi-cms-core.label'))
                                    ->required()
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                                        $set('slug', Str::slug($state));
                                    })->maxLength(255),
                                TextInput::make('slug')
                                    ->label(__('phi-cms-core::phi-cms-core.slug'))
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('url')
                                    ->label(__('phi-cms-core::phi-cms-core.url'))
                                    ->required(),
                            ])
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')
                    ->label(__('phi-cms-core::phi-cms-core.label'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('phi-cms-core::phi-cms-core.slug'))
                    ->searchable(),
                TextColumn::make('url')
                    ->label(__('phi-cms-core::phi-cms-core.url'))
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
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
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
