<?php

use JFA\PhiCmsCore\Schema\FieldFactory;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;

it('creates a string field', function () {
    $factory = new FieldFactory;

    $field = $factory->make('title', ['type' => 'string', 'required' => true, 'max' => 200, 'label' => 'Title']);

    expect($field)->toBeInstanceOf(StringField::class)
        ->and($field->name)->toBe('title')
        ->and($field->required)->toBeTrue()
        ->and($field->max)->toBe(200)
        ->and($field->label)->toBe('Title');
});

it('creates a text field', function () {
    $factory = new FieldFactory;

    $field = $factory->make('description', ['type' => 'text', 'required' => true, 'max' => 1000]);

    expect($field)->toBeInstanceOf(TextField::class)
        ->and($field->name)->toBe('description')
        ->and($field->required)->toBeTrue()
        ->and($field->max)->toBe(1000);
});

it('creates a select field', function () {
    $factory = new FieldFactory;

    $field = $factory->make('level', ['type' => 'select', 'required' => true, 'options' => ['h1' => 'Heading 1']]);

    expect($field)->toBeInstanceOf(SelectField::class)
        ->and($field->options)->toBe(['h1' => 'Heading 1']);
});

it('creates a rich field', function () {
    $factory = new FieldFactory;

    $field = $factory->make('content', ['type' => 'rich', 'required' => true]);

    expect($field)->toBeInstanceOf(RichField::class);
});

it('creates a repeater field with nested fields recursively', function () {
    $factory = new FieldFactory;

    $field = $factory->make('items', [
        'type' => 'repeater',
        'min' => 1,
        'fields' => [
            'title' => ['type' => 'string', 'required' => true],
            'description' => ['type' => 'text', 'required' => true],
        ],
    ]);

    expect($field)->toBeInstanceOf(RepeaterField::class)
        ->and($field->min)->toBe(1)
        ->and($field->fields)->toHaveCount(2)
        ->and($field->fields['title'])->toBeInstanceOf(StringField::class)
        ->and($field->fields['description'])->toBeInstanceOf(TextField::class);
});

it('creates a repeater field with a label', function () {
    $factory = new FieldFactory;

    $field = $factory->make('items', [
        'type' => 'repeater',
        'label' => 'blocks.services.items.label',
        'fields' => [
            'title' => ['type' => 'string', 'required' => true],
        ],
    ]);

    expect($field)->toBeInstanceOf(RepeaterField::class)
        ->and($field->label)->toBe('blocks.services.items.label');
});

it('creates a string field marked as live', function () {
    $factory = new FieldFactory;

    $field = $factory->make('title', ['type' => 'string', 'live' => true]);

    expect($field)->toBeInstanceOf(StringField::class)
        ->and($field->live)->toBeTrue();
});

it('creates a repeater field with a label field for item labels', function () {
    $factory = new FieldFactory;

    $field = $factory->make('items', [
        'type' => 'repeater',
        'label_field' => 'title',
        'fields' => [
            'title' => ['type' => 'string', 'required' => true],
        ],
    ]);

    expect($field)->toBeInstanceOf(RepeaterField::class)
        ->and($field->labelField)->toBe('title');
});

it('creates a custom field', function () {
    $factory = new FieldFactory;

    $field = $factory->make('posts', ['type' => 'custom', 'handler' => 'posts_chooser']);

    expect($field)->toBeInstanceOf(CustomField::class)
        ->and($field->handler)->toBe('posts_chooser');
});

it('creates a posts_chooser field as custom', function () {
    $factory = new FieldFactory;

    $field = $factory->make('posts', ['type' => 'posts_chooser']);

    expect($field)->toBeInstanceOf(CustomField::class)
        ->and($field->handler)->toBe('posts_chooser');
});

it('throws for unsupported field types', function () {
    $factory = new FieldFactory;

    $factory->make('unknown', ['type' => 'unknown']);
})->throws(InvalidArgumentException::class);

it('throws when field configuration is not an array', function () {
    $factory = new FieldFactory;

    $factory->makeFields(['title' => 'not-an-array']);
})->throws(InvalidArgumentException::class);
