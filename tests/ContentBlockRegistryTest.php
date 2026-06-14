<?php

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;

it('can register and retrieve blocks', function () {
    $registry = new ContentBlockRegistry;

    $registry->registerDefault(TestHeadingBlock::class);
    $registry->registerCustom(TestHeroBlock::class);

    expect($registry->getAll())->toHaveCount(2);
});

it('can find a block by name', function () {
    $registry = new ContentBlockRegistry;

    $registry->registerDefault(TestHeadingBlock::class);
    $registry->registerCustom(TestHeroBlock::class);

    $block = $registry->getByName('hero');

    expect($block)->toBeInstanceOf(Block::class)
        ->and($block->getName())->toBe('hero');
});

it('returns null when block name is not found', function () {
    $registry = new ContentBlockRegistry;

    $registry->registerDefault(TestHeadingBlock::class);

    $block = $registry->getByName('nonexistent');

    expect($block)->toBeNull();
});

class TestHeadingBlock implements ContentBlock
{
    public static function make(): Block
    {
        return Block::make('heading')
            ->schema([
                TextInput::make('content')->required(),
            ])
            ->label('Heading')
            ->icon(Heroicon::OutlinedBars3BottomLeft);
    }
}

class TestHeroBlock implements ContentBlock
{
    public static function make(): Block
    {
        return Block::make('hero')
            ->schema([
                TextInput::make('headline')->required(),
            ])
            ->label('Hero')
            ->icon(Heroicon::OutlinedStar);
    }
}
