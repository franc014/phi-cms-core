<?php

namespace JFA\PhiCmsCore\Forms;

use Filament\Forms\Components\Builder;
use Filament\Support\Enums\Alignment;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;

class ContentBuilder
{
    public static function make(): Builder
    {
        $registry = app(ContentBlockRegistry::class);

        return Builder::make('content')
            ->blockNumbers(false)
            ->blockIcons()
            ->addActionLabel('Add a new block')
            ->addActionAlignment(Alignment::Start)
            ->collapsible()
            ->collapsed()
            ->cloneable()
            ->required()
            ->blocks($registry->getAll());
    }
}
