<?php

namespace JFA\PhiCmsCore\Resources\Posts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use JFA\PhiCmsCore\Forms\Components\ContentEditor;
use JFA\PhiCmsCore\Forms\Components\SharedFields;

class PostForm
{
    use SharedFields;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('phi-cms-core::phi-cms-core.basic_info'))
                    ->schema([
                        ...self::titleAndSlugFields(),
                        Textarea::make('excerpt')
                            ->label(__('phi-cms-core::phi-cms-core.excerpt'))
                            ->required()
                            ->columnSpanFull(),
                        SpatieTagsInput::make('tags')
                            ->label(__('phi-cms-core::phi-cms-core.tags'))
                            ->type('categories'),
                        DateTimePicker::make('published_at')
                            ->label(__('phi-cms-core::phi-cms-core.published_at')),
                    ])->collapsible()->collapsed(),
                Section::make(__('phi-cms-core::phi-cms-core.seo'))
                    ->schema([
                        self::metatagsField()
                            ->columnSpanFull(),
                    ])->collapsible()->collapsed(),
                Section::make(__('phi-cms-core::phi-cms-core.content'))
                    ->schema([
                        ContentEditor::make('content')
                            ->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }
}
