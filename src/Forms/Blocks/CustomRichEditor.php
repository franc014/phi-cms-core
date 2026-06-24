<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\Concerns\RendersSchemaFields;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

class CustomRichEditor implements ContentBlock
{
    use BlockSettings;
    use RendersSchemaFields;

    public static function make(): Block
    {
        $schema = app(SchemaRegistry::class)->get('custom_rich_editor');

        return Block::make($schema->type)
            ->schema(static::schemaComponents('custom_rich_editor'))
            ->label(function (?array $state): string {
                return self::setBlockLabelRich($state, 'Rich Editor');
            })
            ->icon($schema->adminMeta['icon']);
    }
}
