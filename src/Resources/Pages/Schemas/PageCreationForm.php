<?php

namespace JFA\PhiCmsCore\Resources\Pages\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Pages\PageRegistry;

final class PageCreationForm
{
    /** @return list<Component> */
    public static function components(): array
    {
        return [
            Select::make('template')
                ->label('Template')
                ->options(fn (): array => collect(app(PageRegistry::class)->all())
                    ->mapWithKeys(fn (string $template, string $key): array => [$key => Str::headline($key)])
                    ->all())
                ->required()
                ->native(false),
            TextInput::make('title')
                ->required()
                ->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    if ($get('_slug_edited') !== true) {
                        $set('slug', Str::slug($state ?? ''));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(100)
                ->helperText('This draft slug does not reserve a public URL.')
                ->afterStateUpdated(function (Set $set): void {
                    $set('_slug_edited', true);
                }),
            Textarea::make('description')
                ->required(),
            Hidden::make('_slug_edited')
                ->default(false)
                ->dehydrated(false),
            Hidden::make('_submission_token')
                ->default(fn (): string => app(PageContent::class)->issueSubmissionToken('create-page')),
        ];
    }
}
