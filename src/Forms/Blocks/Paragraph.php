<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;

class Paragraph implements ContentBlock
{
    use BlockSettings;

    public static function make(): Block
    {
        return Block::make('paragraph')
            ->schema([
                Textarea::make('content')
                    ->label('Paragraph')
                    ->maxLength(2048)
                    ->live(debounce: 500)
                    ->required(),
            ])
            ->label(function (?array $state): string {
                return self::setBlockLabel($state, 'Paragraph');
            })
            ->icon(Heroicon::Bars3BottomLeft);
    }
}
