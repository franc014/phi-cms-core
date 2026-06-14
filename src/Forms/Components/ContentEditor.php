<?php

namespace JFA\PhiCmsCore\Forms\Components;

use Filament\Forms\Components\RichEditor;
use JFA\PhiCmsCore\Forms\Components\RichEditor\StandardToolbar;

class ContentEditor
{
    use StandardToolbar;

    public static function make(string $name): RichEditor
    {
        return RichEditor::make($name)
            ->json()
            ->label(__('phi-cms-core::phi-cms-core.content'))
            ->toolbarButtons(self::toolBarButtons())
            ->live(debounce: 500)
            ->required()
            ->extraInputAttributes([
                'style' => 'height: 25vh; min-height: 300px; overflow-y: auto;',
            ]);
    }
}
