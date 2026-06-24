<?php

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

it('registers admin block classes from the schema config', function () {
    config()->set('phi-cms-schema.blocks', [
        'heading' => [
            'admin' => ['class' => SchemaHeadingBlock::class],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeadingData',
            'fields' => [],
        ],
        'hero' => [
            'admin' => ['class' => SchemaHeroBlock::class],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeroData',
            'fields' => [],
        ],
    ]);

    app()->forgetInstance(SchemaRegistry::class);

    $registry = new ContentBlockRegistry;
    $schemaRegistry = app(SchemaRegistry::class);

    foreach ($schemaRegistry->all() as $schema) {
        if ($schema->adminClass !== null) {
            $registry->register($schema->adminClass);
        }
    }

    expect($registry->getAll())->toHaveCount(2)
        ->and($registry->getByName('heading'))->toBeInstanceOf(Block::class)
        ->and($registry->getByName('hero'))->toBeInstanceOf(Block::class);
});

it('ignores schema entries without an admin class', function () {
    config()->set('phi-cms-schema.blocks', [
        'heading' => [
            'admin' => ['class' => SchemaHeadingBlock::class],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeadingData',
            'fields' => [],
        ],
        'dto_only' => [
            'dto' => 'App\\CMS\\Blocks\\Data\\DtoOnlyData',
            'fields' => [],
        ],
    ]);

    app()->forgetInstance(SchemaRegistry::class);

    $registry = new ContentBlockRegistry;
    $schemaRegistry = app(SchemaRegistry::class);

    foreach ($schemaRegistry->all() as $schema) {
        if ($schema->adminClass !== null) {
            $registry->register($schema->adminClass);
        }
    }

    expect($registry->getAll())->toHaveCount(1)
        ->and($registry->getByName('dto_only'))->toBeNull();
});

class SchemaHeadingBlock implements ContentBlock
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

class SchemaHeroBlock implements ContentBlock
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
