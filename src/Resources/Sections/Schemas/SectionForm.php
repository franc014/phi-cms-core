<?php

namespace JFA\PhiCmsCore\Resources\Sections\Schemas;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use JFA\PhiCmsCore\Forms\Components\SharedFields;
use JFA\PhiCmsCore\Forms\ContentBuilder;

class SectionForm
{
    use SharedFields;

    public static function globalInfoFields(): array
    {
        return [
            ...self::titleAndSlugFields(),
            Textarea::make('description')
                ->label(__('phi-cms-core::phi-cms-core.description'))
                ->required()
                ->minLength(10)
                ->maxLength(1280),
            SpatieMediaLibraryFileUpload::make('section_images')
                ->label(__('phi-cms-core::phi-cms-core.section_images'))
                ->image()
                ->maxSize(1024 * 3)
                ->reorderable()
                ->maxFiles(5)
                ->multiple()
                ->imageEditor()
                ->panelLayout('grid')
                ->uploadingMessage(__('phi-cms-core::phi-cms-core.loading_images'))
                ->responsiveImages()
                ->conversion('thumb')
                ->collection('section-images')
                ->visibility('public'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(8)
            ->components([
                Section::make(__('phi-cms-core::phi-cms-core.general_information'))
                    ->secondary()
                    ->columnSpan(3)
                    ->schema(
                        self::globalInfoFields(),
                    ),

                Section::make(__('phi-cms-core::phi-cms-core.section_content'))
                    ->secondary()
                    ->columnSpan(5)
                    ->schema([
                        ContentBuilder::make(),
                    ]),
            ]);
    }
}
