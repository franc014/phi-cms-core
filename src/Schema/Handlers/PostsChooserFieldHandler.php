<?php

namespace JFA\PhiCmsCore\Schema\Handlers;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use JFA\PhiCmsCore\Models\Post;
use JFA\PhiCmsCore\Resources\Posts\Schemas\PostForm;
use JFA\PhiCmsCore\Schema\CustomFieldHandler;
use JFA\PhiCmsCore\Schema\Fields\CustomField;

class PostsChooserFieldHandler implements CustomFieldHandler
{
    public function handle(CustomField $field): Component
    {
        return Select::make($field->name)
            ->label(__('phi-cms-core::phi-cms-core.posts'))
            ->options(fn () => Post::published()->pluck('title', 'id'))
            ->multiple()
            ->searchable()
            ->createOptionForm(fn () => PostForm::configure(new Schema)->getComponents())
            ->createOptionUsing(fn (array $data): int => Post::create($data)->getKey())
            ->required();
    }
}
