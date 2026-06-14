<?php

use Illuminate\Database\Eloquent\Model;
use JFA\PhiCmsCore\CMS\ContentResolver;
use JFA\PhiCmsCore\CMS\SectionContent;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;

beforeEach(function () {
    Model::unguard();
});

afterEach(function () {
    Model::reguard();
});

it('resolves section content without page context', function () {
    $section = new Section([
        'id' => 5,
        'slug' => 'hero',
        'title' => 'Hero',
        'content' => [
            ['type' => 'hero', 'data' => ['label' => 'Test']],
        ],
    ]);

    $resolver = new ContentResolver($section);
    $content = $resolver->resolve();

    expect($content)->toBeInstanceOf(SectionContent::class)
        ->and($content->hero->label)->toBe('Test');
});

it('resolves section content with page context', function () {
    $section = new Section([
        'id' => 5,
        'slug' => 'hero',
        'title' => 'Hero',
        'content' => [
            ['type' => 'hero', 'data' => ['label' => 'Test']],
        ],
    ]);

    $resolver = new ContentResolver($section, pageId: 2);
    $content = $resolver->resolve();

    expect($content)->toBeInstanceOf(SectionContent::class)
        ->and($content->hero->label)->toBe('Test');

    $sourceMap = $content->hero->getSourceMap();

    expect($sourceMap)->not->toBeNull()
        ->and($sourceMap['page_id'] ?? null)->toBe(2)
        ->and($sourceMap['section_id'] ?? null)->toBe(5)
        ->and($sourceMap['section_slug'] ?? null)->toBe('hero')
        ->and($sourceMap['block_type'] ?? null)->toBe('hero')
        ->and($sourceMap['block_index'] ?? null)->toBe(0);
});

it('page model passes context to content resolver', function () {
    $page = new Page([
        'id' => 10,
        'slug' => 'test',
        'title' => 'Test Page',
    ]);

    $section = new Section([
        'id' => 5,
        'slug' => 'hero',
        'title' => 'Hero',
        'content' => [
            ['type' => 'hero', 'data' => ['label' => 'Test']],
        ],
    ]);

    $page->setRelation('sections', collect([$section]));

    $sectionsForUI = $page->sectionsForUI();

    expect($sectionsForUI)->toHaveKey('hero');

    $content = $sectionsForUI['hero']['content'];

    $sourceMap = $content->hero->getSourceMap();

    expect($sourceMap)->not->toBeNull()
        ->and($sourceMap['page_id'] ?? null)->toBe(10)
        ->and($sourceMap['section_id'] ?? null)->toBe(5)
        ->and($sourceMap['section_slug'] ?? null)->toBe('hero');
});

it('content resolver handles sections without media', function () {
    $section = new Section([
        'id' => 5,
        'slug' => 'hero',
        'title' => 'Hero',
        'content' => [
            ['type' => 'hero', 'data' => ['label' => 'Test']],
        ],
    ]);

    $resolver = new ContentResolver($section, pageId: 2);
    $content = $resolver->resolve();

    expect($content->images)->toBeNull();
});
