<?php

use JFA\PhiCmsCore\Forms\Blocks\CustomRichEditor;
use JFA\PhiCmsCore\Forms\Blocks\Heading;
use JFA\PhiCmsCore\Forms\Blocks\Paragraph;
use JFA\PhiCmsCore\Forms\Blocks\PostsChooser;
use JFA\PhiCmsCore\Forms\Components\RichEditor\RichContentCustomBlocks\CTABlock;
use JFA\PhiCmsPresenter\Data\CustomRichEditorData;
use JFA\PhiCmsPresenter\Data\HeadingData;
use JFA\PhiCmsPresenter\Data\ParagraphData;
use JFA\PhiCmsPresenter\Data\PostsChooserData;

return [
    /*
    |--------------------------------------------------------------------------
    | PHI CMS Block Schema
    |--------------------------------------------------------------------------
    |
    | This file declares every content block used by the CMS. It is the single
    | source of truth for block type strings, field shapes, admin block classes,
    | and presenter DTO classes.
    |
    | Both phi-cms-core and phi-cms-presenter consume this config.
    |
    */

    'blocks' => [
        'heading' => [
            'admin' => [
                'class' => Heading::class,
                'icon' => 'heroicon-o-bars-2',
            ],
            'dto' => HeadingData::class,
            'fields' => [
                'content' => [
                    'type' => 'string',
                    'required' => true,
                    'max' => 200,
                    'label' => 'phi-cms-core::phi-cms-core.heading_content',
                ],
                'level' => [
                    'type' => 'select',
                    'required' => true,
                    'options' => [
                        'h1' => 'phi-cms-core::phi-cms-core.heading_1',
                        'h2' => 'phi-cms-core::phi-cms-core.heading_2',
                        'h3' => 'phi-cms-core::phi-cms-core.heading_3',
                        'h4' => 'phi-cms-core::phi-cms-core.heading_4',
                        'h5' => 'phi-cms-core::phi-cms-core.heading_5',
                        'h6' => 'phi-cms-core::phi-cms-core.heading_6',
                    ],
                    'label' => 'phi-cms-core::phi-cms-core.heading_level',
                ],
            ],
        ],

        'paragraph' => [
            'admin' => [
                'class' => Paragraph::class,
                'icon' => 'heroicon-o-bars-3-bottom-left',
            ],
            'dto' => ParagraphData::class,
            'fields' => [
                'content' => [
                    'type' => 'text',
                    'required' => true,
                    'max' => 2048,
                    'label' => 'phi-cms-core::phi-cms-core.paragraph_content',
                ],
            ],
        ],

        'custom_rich_editor' => [
            'admin' => [
                'class' => CustomRichEditor::class,
                'icon' => 'heroicon-o-bars-4',
            ],
            'dto' => CustomRichEditorData::class,
            'fields' => [
                'content' => [
                    'type' => 'rich',
                    'required' => true,
                    'label' => 'phi-cms-core::phi-cms-core.rich_editor_content',
                    'custom_blocks' => [CTABlock::class],
                    'json' => true,
                ],
            ],
        ],

        'posts_chooser' => [
            'admin' => [
                'class' => PostsChooser::class,
                'icon' => 'heroicon-o-document-check',
            ],
            'dto' => PostsChooserData::class,
            'fields' => [
                'posts' => [
                    'type' => 'posts_chooser',
                    'handler' => 'posts_chooser',
                ],
            ],
        ],
    ],
];
