<?php

use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;
use JFA\PhiCmsCore\Schema\Schema;

it('can retrieve a field by name', function () {
    $schema = new Schema(
        type: 'services',
        adminClass: 'App\\CMS\\Blocks\\Services',
        dtoClass: 'App\\CMS\\Blocks\\Data\\ServicesData',
        adminMeta: ['label' => 'Services'],
        fields: [
            'section_title' => new StringField('section_title', true, 200, 'Section Title'),
            'items' => new RepeaterField('items', 1, null, null, [
                'title' => new StringField('title', true, 200, 'Title'),
                'description' => new TextField('description', true, 1000, 'Description'),
            ]),
        ],
    );

    expect($schema->field('section_title'))->toBeInstanceOf(StringField::class)
        ->and($schema->field('items'))->toBeInstanceOf(RepeaterField::class)
        ->and($schema->field('missing'))->toBeNull();
});

it('can determine if a field is repeatable', function () {
    $schema = new Schema(
        type: 'services',
        adminClass: 'App\\CMS\\Blocks\\Services',
        dtoClass: 'App\\CMS\\Blocks\\Data\\ServicesData',
        adminMeta: [],
        fields: [
            'section_title' => new StringField('section_title', true, 200, null),
            'items' => new RepeaterField('items', 1, null, null, []),
        ],
    );

    expect($schema->isRepeatable('items'))->toBeTrue()
        ->and($schema->isRepeatable('section_title'))->toBeFalse()
        ->and($schema->isRepeatable('missing'))->toBeFalse();
});
