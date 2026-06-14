<?php

use JFA\PhiCmsCore\CMS\BlockData;
use JFA\PhiCmsCore\CMS\NullBlockData;
use JFA\PhiCmsCore\CMS\SectionContent;

it('can be created with source map context', function () {
    $block = new BlockData(['label' => 'Test']);
    $context = [
        'section_id' => 5,
        'section_slug' => 'hero',
        'block_type' => 'hero',
        'block_index' => 0,
        'page_id' => 2,
    ];

    $mappedBlock = $block->withSourceMap($context);

    expect($mappedBlock)->toBeInstanceOf(BlockData::class)
        ->and($mappedBlock)->not->toBe($block);
});

it('returns source map context when set', function () {
    $context = [
        'section_id' => 5,
        'section_slug' => 'hero',
        'block_type' => 'hero',
        'block_index' => 0,
        'page_id' => 2,
    ];
    $block = (new BlockData(['label' => 'Hello World']))->withSourceMap($context);

    expect($block->getSourceMap())->toBe($context);
});

it('returns null source map when not set', function () {
    $block = new BlockData(['label' => 'Hello World']);

    expect($block->getSourceMap())->toBeNull();
});

it('maintains backward compatibility without source maps', function () {
    $block = new BlockData(['label' => 'Test']);

    expect($block->label)->toBe('Test')
        ->and($block->getSourceMap())->toBeNull();
});

it('works with null block data', function () {
    $nullBlock = new NullBlockData;

    expect($nullBlock->getSourceMap())->toBeNull();
});

it('section content accepts optional source map context', function () {
    $content = new SectionContent([
        'hero' => [
            ['label' => 'About', 'headline' => 'Hello'],
        ],
        'images' => null,
    ], pageId: 2, sectionId: 5, sectionSlug: 'hero');

    expect($content->hero)->toBeInstanceOf(BlockData::class)
        ->and($content->hero->label)->toBe('About');
});

it('section content works without source map context', function () {
    $content = new SectionContent([
        'hero' => [
            ['label' => 'About'],
        ],
        'images' => null,
    ]);

    expect($content->hero->label)->toBe('About');
});

it('section content injects source maps into block data when context provided', function () {
    $content = new SectionContent([
        'hero' => [
            ['label' => 'About'],
        ],
        'images' => null,
    ], pageId: 2, sectionId: 5, sectionSlug: 'hero');

    $sourceMap = $content->hero->getSourceMap();

    expect($sourceMap)->not->toBeNull()
        ->and($sourceMap['section_id'] ?? null)->toBe(5)
        ->and($sourceMap['page_id'] ?? null)->toBe(2)
        ->and($sourceMap['section_slug'] ?? null)->toBe('hero')
        ->and($sourceMap['block_type'] ?? null)->toBe('hero')
        ->and($sourceMap['block_index'] ?? null)->toBe(0);
});

it('section content handles multiple block types with source maps', function () {
    $content = new SectionContent([
        'hero' => [
            ['label' => 'Hero Label'],
        ],
        'mission' => [
            ['title' => 'Mission Title'],
        ],
        'images' => null,
    ], pageId: 2, sectionId: 5, sectionSlug: 'about');

    $heroMap = $content->hero->getSourceMap();
    $missionMap = $content->mission->getSourceMap();

    expect($heroMap['block_type'] ?? null)->toBe('hero')
        ->and($missionMap['block_type'] ?? null)->toBe('mission');
});

it('section content handles block index correctly', function () {
    $content = new SectionContent([
        'paragraph' => [
            ['content' => 'First'],
            ['content' => 'Second'],
        ],
        'images' => null,
    ], pageId: 2, sectionId: 5, sectionSlug: 'content');

    $firstMap = $content->all('paragraph')[0]->getSourceMap();
    $secondMap = $content->all('paragraph')[1]->getSourceMap();

    expect($firstMap['block_index'] ?? null)->toBe(0)
        ->and($secondMap['block_index'] ?? null)->toBe(1);
});
