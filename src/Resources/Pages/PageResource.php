<?php

namespace JFA\PhiCmsCore\Resources\Pages;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Enums\NavigationGroup;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Resources\Pages\Pages\ListPages;
use JFA\PhiCmsCore\Resources\Pages\Pages\ManagePageSections;
use JFA\PhiCmsCore\Resources\Pages\Schemas\PageForm;
use JFA\PhiCmsCore\Resources\Pages\Tables\PagesTable;
use UnitEnum;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocument;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::CMS;

    public static function getModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.page');
    }

    public static function getPluralModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.pages');
    }

    public static function form(Schema $schema): Schema
    {
        return PageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'manageSections' => ManagePageSections::route('/{record}/sections'),
        ];
    }
}
