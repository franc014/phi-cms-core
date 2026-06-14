<?php

namespace JFA\PhiCmsCore\Resources\Categories;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Enums\NavigationGroup;
use JFA\PhiCmsCore\Models\Category;
use JFA\PhiCmsCore\Resources\Categories\Pages\CreateCategory;
use JFA\PhiCmsCore\Resources\Categories\Pages\EditCategory;
use JFA\PhiCmsCore\Resources\Categories\Pages\ListCategories;
use JFA\PhiCmsCore\Resources\Categories\Schemas\CategoryForm;
use JFA\PhiCmsCore\Resources\Categories\Tables\CategoriesTable;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::BLOG;

    public static function getModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.categories');
    }

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
