<?php

use JFA\PhiCmsCore\Schema\FieldFactory;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Schema;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

it('can be constructed from a config array', function () {
    $registry = SchemaRegistry::fromConfig([
        'services' => [
            'admin' => [
                'class' => 'App\\CMS\\Blocks\\Services',
                'label' => 'Services',
                'icon' => 'outlined-wrench-screwdriver',
            ],
            'dto' => 'App\\CMS\\Blocks\\Data\\ServicesData',
            'fields' => [
                'section_title' => ['type' => 'string', 'required' => true, 'max' => 200],
            ],
        ],
    ], new FieldFactory);

    expect($registry->has('services'))->toBeTrue();
    expect($registry->get('services'))->toBeInstanceOf(Schema::class);
});

it('throws when a schema is missing', function () {
    $registry = SchemaRegistry::fromConfig([], new FieldFactory);

    $registry->get('missing');
})->throws(InvalidArgumentException::class);

it('can resolve a type from an admin class', function () {
    $registry = SchemaRegistry::fromConfig([
        'services' => [
            'admin' => ['class' => 'App\\CMS\\Blocks\\Services'],
            'dto' => 'App\\CMS\\Blocks\\Data\\ServicesData',
            'fields' => [],
        ],
    ], new FieldFactory);

    expect($registry->typeForAdminClass('App\\CMS\\Blocks\\Services'))->toBe('services');
});

it('throws when admin class is not registered', function () {
    $registry = SchemaRegistry::fromConfig([], new FieldFactory);

    $registry->typeForAdminClass('App\\CMS\\Blocks\\Services');
})->throws(InvalidArgumentException::class);

it('can resolve a type from a dto class', function () {
    $registry = SchemaRegistry::fromConfig([
        'services' => [
            'admin' => ['class' => 'App\\CMS\\Blocks\\Services'],
            'dto' => 'App\\CMS\\Blocks\\Data\\ServicesData',
            'fields' => [],
        ],
    ], new FieldFactory);

    expect($registry->typeForDtoClass('App\\CMS\\Blocks\\Data\\ServicesData'))->toBe('services');
});

it('throws when dto class is not registered', function () {
    $registry = SchemaRegistry::fromConfig([], new FieldFactory);

    $registry->typeForDtoClass('App\\CMS\\Blocks\\Data\\ServicesData');
})->throws(InvalidArgumentException::class);

it('returns all schemas', function () {
    $registry = SchemaRegistry::fromConfig([
        'heading' => [
            'admin' => ['class' => 'App\\CMS\\Blocks\\Heading'],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeadingData',
            'fields' => [],
        ],
        'paragraph' => [
            'admin' => ['class' => 'App\\CMS\\Blocks\\Paragraph'],
            'dto' => 'App\\CMS\\Blocks\\Data\\ParagraphData',
            'fields' => [],
        ],
    ], new FieldFactory);

    expect($registry->all())->toHaveCount(2);
});

it('is populated from the phi-cms-schema config', function () {
    config()->set('phi-cms-schema.blocks', [
        'heading' => [
            'admin' => ['class' => 'App\\CMS\\Blocks\\Heading'],
            'dto' => 'App\\CMS\\Blocks\\Data\\HeadingData',
            'fields' => [
                'content' => ['type' => 'string', 'required' => true],
            ],
        ],
    ]);

    // Force re-resolution so the new config is read.
    app()->forgetInstance(SchemaRegistry::class);

    $registry = app(SchemaRegistry::class);

    expect($registry->has('heading'))->toBeTrue()
        ->and($registry->get('heading')->field('content'))->toBeInstanceOf(StringField::class);
});
