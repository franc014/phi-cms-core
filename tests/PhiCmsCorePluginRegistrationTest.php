<?php

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;
use JFA\PhiCmsCore\PhiCmsCorePlugin;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

it('registers content blocks from the schema config', function () {
    config()->set('phi-cms-schema.blocks', [
        'heading' => [
            'admin' => ['class' => PluginHeadingBlock::class],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeadingData',
            'fields' => [],
        ],
    ]);

    config()->set('phi-cms-core.content_blocks', [
        'default' => [PluginLegacyBlock::class],
        'custom' => [],
    ]);

    app()->forgetInstance(SchemaRegistry::class);
    app()->singleton(ContentBlockRegistry::class);

    $plugin = PhiCmsCorePlugin::make();
    $method = new ReflectionMethod($plugin, 'registerContentBlocks');
    $method->setAccessible(true);
    $method->invoke($plugin);

    $registry = app(ContentBlockRegistry::class);

    expect($registry->getByName('heading'))->toBeInstanceOf(Block::class)
        ->and($registry->getByName('legacy'))->toBeNull();
});

class PluginHeadingBlock implements ContentBlock
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

class PluginLegacyBlock implements ContentBlock
{
    public static function make(): Block
    {
        return Block::make('legacy')
            ->schema([
                TextInput::make('content')->required(),
            ])
            ->label('Legacy')
            ->icon(Heroicon::OutlinedExclamationCircle);
    }
}
