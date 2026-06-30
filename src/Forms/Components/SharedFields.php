<?php

namespace JFA\PhiCmsCore\Forms\Components;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

trait SharedFields
{
    public static function metatagsField(): Section
    {
        return Section::make(__('phi-cms-core::phi-cms-core.metatags'))
            ->relationship('metatags')
            ->schema([
                TextInput::make('title')
                    ->label(__('phi-cms-core::phi-cms-core.title'))
                    ->maxLength(100),
                Textarea::make('description')
                    ->label(__('phi-cms-core::phi-cms-core.description'))
                    ->rows(3),
                Fieldset::make('Open Graph')
                    ->schema([
                        TextInput::make('og_title')
                            ->label(__('phi-cms-core::phi-cms-core.og_title'))
                            ->maxLength(100),
                        Textarea::make('og_description')
                            ->label(__('phi-cms-core::phi-cms-core.og_description'))
                            ->rows(2),
                        FileUpload::make('og_image')
                            ->label(__('phi-cms-core::phi-cms-core.og_image'))
                            ->image()
                            ->directory('metatags')
                            ->visibility('public'),
                    ]),
                Fieldset::make('Twitter')
                    ->schema([
                        TextInput::make('twitter_title')
                            ->label(__('phi-cms-core::phi-cms-core.twitter_title'))
                            ->maxLength(100),
                        Select::make('twitter_card')
                            ->label(__('phi-cms-core::phi-cms-core.twitter_card'))
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary Large Image',
                            ])
                            ->default('summary_large_image'),
                        Textarea::make('twitter_description')
                            ->label(__('phi-cms-core::phi-cms-core.twitter_description'))
                            ->rows(2),
                        FileUpload::make('twitter_image')
                            ->label(__('phi-cms-core::phi-cms-core.twitter_image'))
                            ->image()
                            ->directory('metatags')
                            ->visibility('public'),
                    ]),
                Select::make('robots')
                    ->label(__('phi-cms-core::phi-cms-core.robots'))
                    ->options([
                        'index,follow' => 'Index, Follow',
                        'noindex,follow' => 'No Index, Follow',
                        'index,nofollow' => 'Index, No Follow',
                        'noindex,nofollow' => 'No Index, No Follow',
                    ])
                    ->default('index,follow'),
            ]);
    }

    public static function titleAndSlugFields(string $additionalSlugable = ''): array
    {
        return [
            TextInput::make('title')
                ->label(__('phi-cms-core::phi-cms-core.title'))
                ->required()
                ->maxLength(100)
                ->live(debounce: 500)
                ->afterStateUpdated(function (Set $set, ?string $state) use ($additionalSlugable) {
                    $set('slug', Str::slug($state));
                    $additionalSlugable ? $set($additionalSlugable, Str::slug($state)) : null;
                }),
            TextInput::make('slug')
                ->label(__('phi-cms-core::phi-cms-core.slug'))
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(100),
        ];
    }

    public static function urlSelector(): array
    {
        return [
            Radio::make('url_input_type')
                ->options([
                    'open' => 'Open',
                    'page' => 'Page',
                ])
                ->inline()
                ->default('open')
                ->required()
                ->live(),
            TextInput::make('url')
                ->required()
                ->hidden(fn (Get $get): bool => $get('url_input_type') === 'page'),

            Select::make('url')->options(function () {
                //
            })->required()->hidden(fn (Get $get): bool => $get('url_input_type') === 'open'),
        ];
    }
}
