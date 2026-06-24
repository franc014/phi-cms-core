<?php

use JFA\PhiCmsCore\Forms\Blocks\Heading;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsPresenter\Data\HeadingData;

it('registers the heading schema from core defaults', function () {
    $schema = app(SchemaRegistry::class)->get('heading');

    expect($schema->type)->toBe('heading')
        ->and($schema->adminClass)->toBe(Heading::class)
        ->and($schema->dtoClass)->toBe(HeadingData::class)
        ->and($schema->adminMeta['icon'])->toBe('heroicon-o-bars-2');
});

it('exposes the expected heading fields', function () {
    $schema = app(SchemaRegistry::class)->get('heading');

    expect($schema->field('content'))->toBeInstanceOf(StringField::class)
        ->and($schema->field('level'))->toBeInstanceOf(SelectField::class);

    expect($schema->field('handle'))->toBeNull();
});

it('provides heading level options', function () {
    /** @var SelectField $level */
    $level = app(SchemaRegistry::class)->get('heading')->field('level');

    expect($level->options)->toHaveKeys(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
});

it('builds a heading block with the correct name', function () {
    expect(Heading::make()->getName())->toBe('heading');
});
