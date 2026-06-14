<?php

namespace JFA\PhiCmsCore\Forms\Components;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

trait SharedFields
{
    public static function metatagsField(): KeyValue
    {
        return KeyValue::make('metatags')
            ->label(__('phi-cms-core::phi-cms-core.metatags'))
            ->keyLabel(__('phi-cms-core::phi-cms-core.meta_tag'))
            ->addActionLabel(__('phi-cms-core::phi-cms-core.add_meta_tag'))
            ->required()
            ->keyPlaceholder(__('phi-cms-core::phi-cms-core.meta_tag'))
            ->valuePlaceholder(__('phi-cms-core::phi-cms-core.meta_tag_value'))
            ->default([
                'og_title' => '',
                'og_description' => '',
                'og_image' => '',
                'twitter_card' => 'summary_large_image',
                'twitter_title' => '',
                'twitter_description' => '',
                'twitter_image' => '',
                'robots' => 'index,follow',
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
