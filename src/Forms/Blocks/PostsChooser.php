<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Contracts\ContentBlock;
use JFA\PhiCmsCore\Models\Post;
use JFA\PhiCmsCore\Resources\Posts\Schemas\PostForm;

class PostsChooser implements ContentBlock
{
    use BlockSettings;

    public static function make(): Block
    {
        return Block::make('posts')
            ->schema([
                Select::make('posts')
                    ->label(__('phi-cms-core::phi-cms-core.posts'))
                    ->options(fn () => Post::published()->pluck('title', 'id'))
                    ->multiple()
                    ->searchable()
                    ->createOptionForm(fn () => PostForm::configure(new Schema)->getComponents())
                    ->createOptionUsing(fn (array $data): int => Post::create($data)->getKey())
                    ->required(),
            ])
            ->label(__('phi-cms-core::phi-cms-core.posts_chooser'))
            ->icon(Heroicon::OutlinedDocumentCheck);
    }
}
