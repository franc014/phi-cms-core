<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Forms\Blocks\Concerns\RendersSchemaFields;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

class PostsChooser implements ContentBlock
{
    use BlockSettings;
    use RendersSchemaFields;

    public static function make(): Block
    {
        $schema = app(SchemaRegistry::class)->get('posts_chooser');

        return Block::make($schema->type)
            ->schema(static::schemaComponents('posts_chooser'))
            ->label(__('phi-cms-core::phi-cms-core.posts_chooser'))
            ->icon($schema->adminMeta['icon']);
    }
}
