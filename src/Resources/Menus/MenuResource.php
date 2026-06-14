<?php

namespace JFA\PhiCmsCore\Resources\Menus;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Enums\NavigationGroup;
use JFA\PhiCmsCore\Models\Menu;
use JFA\PhiCmsCore\Resources\Menus\Pages\EditMenu;
use JFA\PhiCmsCore\Resources\Menus\Pages\ListMenus;
use JFA\PhiCmsCore\Resources\Menus\RelationManagers\ItemsRelationManager;
use JFA\PhiCmsCore\Resources\Menus\Schemas\MenuForm;
use JFA\PhiCmsCore\Resources\Menus\Tables\MenusTable;
use UnitEnum;

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBars4;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::CMS;

    public static function getModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.menu');
    }

    public static function getPluralModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.menus');
    }

    public static function form(Schema $schema): Schema
    {
        return MenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenusTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
