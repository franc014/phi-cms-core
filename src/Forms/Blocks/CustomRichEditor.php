<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\RichEditor;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Components\RichEditor\RichContentCustomBlocks\CTABlock;

class CustomRichEditor implements ContentBlock
{
    use BlockSettings;

    public static function make(): Block
    {
        return Block::make('rich-editor')
            ->schema([
                RichEditor::make('content')
                    ->customBlocks([
                        CTABlock::class,
                    ])
                    ->json()
                    ->label('Rich Editor')
                    ->live(debounce: 500)
                    ->required(),
            ])
            ->label(function (?array $state): string {
                return self::setBlockLabelRich($state, 'Rich Editor');
            })
            ->icon(Heroicon::Bars4);
    }
}
