<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\Concerns\RendersSchemaFields;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

class Heading implements ContentBlock
{
    use BlockSettings;
    use RendersSchemaFields;

    public static function make(): Block
    {
        $schema = app(SchemaRegistry::class)->get('heading');

        return Block::make($schema->type)
            ->schema(static::schemaComponents('heading'))
            ->label(function (?array $state): string {
                return self::setBlockLabel($state, 'Heading');
            })
            ->icon($schema->adminMeta['icon']);
    }
}
