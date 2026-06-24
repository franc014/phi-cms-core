<?php

use Filament\Forms\Components\Select;
use JFA\PhiCmsCore\Forms\Blocks\PostsChooser;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\SchemaFieldRenderer;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsPresenter\Data\PostsChooserData;

it('registers the posts chooser schema from core defaults', function () {
    $schema = app(SchemaRegistry::class)->get('posts_chooser');

    expect($schema->type)->toBe('posts_chooser')
        ->and($schema->adminClass)->toBe(PostsChooser::class)
        ->and($schema->dtoClass)->toBe(PostsChooserData::class)
        ->and($schema->adminMeta['icon'])->toBe('heroicon-o-document-check');
});

it('exposes a custom field handled by posts_chooser', function () {
    /** @var CustomField $posts */
    $posts = app(SchemaRegistry::class)->get('posts_chooser')->field('posts');

    expect($posts)->toBeInstanceOf(CustomField::class)
        ->and($posts->handler)->toBe('posts_chooser');
});

it('renders the posts field as a multiple searchable select', function () {
    $component = app(SchemaFieldRenderer::class)->render(
        app(SchemaRegistry::class)->get('posts_chooser')->field('posts')
    );

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->isMultiple())->toBeTrue()
        ->and($component->isSearchable())->toBeTrue();
});

it('builds a posts chooser block with the correct name', function () {
    expect(PostsChooser::make()->getName())->toBe('posts_chooser');
});
