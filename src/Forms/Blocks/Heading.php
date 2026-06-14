<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;

class Heading implements ContentBlock
{
    use BlockSettings;

    public static function make(): Block
    {
        return Block::make('heading')
            ->schema([
                TextInput::make('content')
                    ->label('Heading')
                    ->live(debounce: 500)
                    ->required(),
                Select::make('level')
                    ->options([
                        'h1' => 'Heading 1',
                        'h2' => 'Heading 2',
                        'h3' => 'Heading 3',
                        'h4' => 'Heading 4',
                        'h5' => 'Heading 5',
                        'h6' => 'Heading 6',
                    ])->required(),
                TextInput::make('handle')
                    ->minLength(3)
                    ->maxLength(10)
                    ->required(),
            ])
            ->label(function (?array $state): string {
                return self::setBlockLabel($state, 'Heading');
            })
            ->icon(Heroicon::OutlinedBars2);
    }
}
