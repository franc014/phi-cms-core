<?php

namespace JFA\PhiCmsCore\Forms\Components\RichEditor;

trait StandardToolbar
{
    private static function toolBarButtons(): array
    {
        return [
            ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'],
            ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
            ['blockquote', 'codeBlock', 'bulletList', 'orderedList', 'details'],
            ['table', 'attachFiles', 'customBlocks'],
            ['undo', 'redo'],
        ];
    }
}
