<?php

use JFA\PhiCmsCore\Forms\Blocks\Paragraph;
use JFA\PhiCmsCore\Schema\Fields\TextField;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsPresenter\Data\ParagraphData;

it('registers the paragraph schema from core defaults', function () {
    $schema = app(SchemaRegistry::class)->get('paragraph');

    expect($schema->type)->toBe('paragraph')
        ->and($schema->adminClass)->toBe(Paragraph::class)
        ->and($schema->dtoClass)->toBe(ParagraphData::class)
        ->and($schema->adminMeta['icon'])->toBe('heroicon-o-bars-3-bottom-left');
});

it('exposes a text content field with max length', function () {
    /** @var TextField $content */
    $content = app(SchemaRegistry::class)->get('paragraph')->field('content');

    expect($content)->toBeInstanceOf(TextField::class)
        ->and($content->max)->toBe(2048);
});

it('builds a paragraph block with the correct name', function () {
    expect(Paragraph::make()->getName())->toBe('paragraph');
});
