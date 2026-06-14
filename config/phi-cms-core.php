<?php

use JFA\PhiCmsCore\Forms\Blocks\CustomRichEditor;
use JFA\PhiCmsCore\Forms\Blocks\Heading;
use JFA\PhiCmsCore\Forms\Blocks\Paragraph;
use JFA\PhiCmsCore\Forms\Blocks\PostsChooser;

return [
    'content_blocks' => [
        'default' => [
            Heading::class,
            Paragraph::class,
            CustomRichEditor::class,
            PostsChooser::class,
        ],
        'custom' => [
            // \App\CMS\Blocks\CustomBlock::class,
        ],
    ],
];
