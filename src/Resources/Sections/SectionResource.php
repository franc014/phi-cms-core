<?php

namespace JFA\PhiCmsCore\Resources\Sections;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use JFA\PhiCmsCore\Enums\NavigationGroup;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Resources\Sections\Pages\ListSections;
use JFA\PhiCmsCore\Resources\Sections\Schemas\SectionForm;
use JFA\PhiCmsCore\Resources\Sections\Tables\SectionsTable;
use UnitEnum;

class SectionResource extends Resource
{
    protected static ?string $model = Section::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string | UnitEnum | null $navigationGroup = NavigationGroup::CMS;

    public static function getModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.section');
    }

    public static function getPluralModelLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.sections');
    }

    public static function form(Schema $schema): Schema
    {
        return SectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SectionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSections::route('/'),
        ];
    }
}
