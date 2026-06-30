<?php

namespace JFA\PhiCmsCore\Resources\Pages\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Forms\Components\SharedFields;

class PageForm
{
    use SharedFields;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(8)
            ->components([
                Section::make(__('phi-cms-core::phi-cms-core.main_information'))
                    ->description(__('phi-cms-core::phi-cms-core.general_information_about_the_page'))
                    ->collapsible()
                    ->columnSpan(4)
                    ->icon(Heroicon::OutlinedPencil)
                    ->schema([
                        ...self::titleAndSlugFields('route'),
                        Textarea::make('description')
                            ->label(__('phi-cms-core::phi-cms-core.description'))
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('route')
                            ->label(__('phi-cms-core::phi-cms-core.route'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                    ]),

                Section::make(__('phi-cms-core::phi-cms-core.page_content'))
                    ->description(function (): Htmlable {
                        return new HtmlString('<p class="text-sm italic">' . __('phi-cms-core::phi-cms-core.only_active_sections_are_displayed_to_compose_the_page') . '</p>');
                    })
                    ->collapsible()
                    ->columnSpan(4)
                    ->icon(Heroicon::OutlinedRectangleGroup)
                    ->schema([
                        CheckboxList::make('page_sections')
                            ->label(__('phi-cms-core::phi-cms-core.sections'))
                            ->relationship('sections', 'title', function ($query) {
                                return $query->select(['id', 'title'])
                                    ->where('status', '=', SectionStatus::ACTIVE->value);
                            })
                            ->gridDirection('row')
                            ->searchable()
                            ->searchPrompt(__('phi-cms-core::phi-cms-core.search_for_a_section'))
                            ->noSearchResultsMessage(__('phi-cms-core::phi-cms-core.no_sections_found')),
                    ]),

                self::metatagsField()
                    ->collapsible()
                    ->columnSpan(4)
                    ->icon(Heroicon::OutlinedTag),
            ]);
    }
}
