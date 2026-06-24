<?php

use JFA\PhiCmsCore\Forms\Blocks\CustomRichEditor;
use JFA\PhiCmsCore\Forms\Components\RichEditor\RichContentCustomBlocks\CTABlock;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsPresenter\Data\CustomRichEditorData;

it('registers the custom rich editor schema from core defaults', function () {
    $schema = app(SchemaRegistry::class)->get('custom_rich_editor');

    expect($schema->type)->toBe('custom_rich_editor')
        ->and($schema->adminClass)->toBe(CustomRichEditor::class)
        ->and($schema->dtoClass)->toBe(CustomRichEditorData::class)
        ->and($schema->adminMeta['icon'])->toBe('heroicon-o-bars-4');
});

it('exposes a rich content field with cta custom block and json', function () {
    /** @var RichField $content */
    $content = app(SchemaRegistry::class)->get('custom_rich_editor')->field('content');

    expect($content)->toBeInstanceOf(RichField::class)
        ->and($content->customBlocks)->toContain(CTABlock::class)
        ->and($content->json)->toBeTrue();
});

it('builds a custom rich editor block with the correct name', function () {
    expect(CustomRichEditor::make()->getName())->toBe('custom_rich_editor');
});
