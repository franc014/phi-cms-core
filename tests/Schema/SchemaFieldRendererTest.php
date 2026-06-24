<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;
use JFA\PhiCmsCore\Schema\Handlers\PostsChooserFieldHandler;
use JFA\PhiCmsCore\Schema\SchemaFieldRenderer;

it('renders a string field as text input', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new StringField('title', true, 200, 'Title'));

    expect($component)->toBeInstanceOf(TextInput::class)
        ->and($component->getName())->toBe('title');
});

it('renders a text field as textarea', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new TextField('description', true, 1000, null));

    expect($component)->toBeInstanceOf(Textarea::class)
        ->and($component->getName())->toBe('description');
});

it('renders a select field as select', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new SelectField('level', true, ['h1' => 'Heading 1'], null));

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->getName())->toBe('level');
});

it('renders a rich field as rich editor', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new RichField('content', true, null));

    expect($component)->toBeInstanceOf(RichEditor::class)
        ->and($component->getName())->toBe('content');
});

it('renders a rich field with custom blocks and json', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new RichField('content', true, null, customBlocks: [stdClass::class], json: true));

    expect($component)->toBeInstanceOf(RichEditor::class);
});

it('renders a repeater field with nested components', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new RepeaterField('items', 1, null, null, [
        'title' => new StringField('title', true, 200, null),
        'description' => new TextField('description', true, 1000, null),
    ]));

    expect($component)->toBeInstanceOf(Repeater::class)
        ->and($component->getName())->toBe('items');
});

it('throws when rendering an unregistered custom field', function () {
    $renderer = new SchemaFieldRenderer;

    $renderer->render(new CustomField('posts', 'posts_chooser'));
})->throws(InvalidArgumentException::class);

it('renders a registered posts chooser custom field', function () {
    $renderer = new SchemaFieldRenderer([
        'posts_chooser' => app(PostsChooserFieldHandler::class),
    ]);

    $component = $renderer->render(new CustomField('posts', 'posts_chooser'));

    expect($component)->toBeInstanceOf(Select::class)
        ->and($component->getName())->toBe('posts');
});

it('translates field labels', function () {
    app('translator')->addLines(['test.section_title' => 'Translated Title'], 'en');

    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new StringField('section_title', false, null, 'test.section_title'));

    expect($component->getLabel())->toBe('Translated Title');
});

it('translates select option labels', function () {
    app('translator')->addLines(['test.option_one' => 'First Option'], 'en');

    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new SelectField('level', true, ['one' => 'test.option_one'], null));

    expect($component->getOptions())->toBe(['one' => 'First Option']);
});

it('applies the repeater label when provided', function () {
    app('translator')->addLines(['test.items_label' => 'Translated Items'], 'en');

    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new RepeaterField('items', 1, 'test.items_label', null, [
        'title' => new StringField('title', true, 200, null),
    ]));

    expect($component)->toBeInstanceOf(Repeater::class)
        ->and($component->getLabel())->toBe('Translated Items');
});

it('marks fields as live when configured', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new StringField('title', false, null, 'Title', live: true));

    expect($component->isLive())->toBeTrue();
});

it('applies a repeater item label from a nested label field', function () {
    $renderer = new SchemaFieldRenderer;

    $component = $renderer->render(new RepeaterField('items', 1, null, 'title', [
        'title' => new StringField('title', true, 200, null),
    ]));

    expect($component)->toBeInstanceOf(Repeater::class);

    $property = new ReflectionProperty($component, 'itemLabel');
    $property->setAccessible(true);
    $itemLabel = $property->getValue($component);

    expect($itemLabel)->toBeInstanceOf(Closure::class);
    expect($itemLabel(['title' => 'First Service']))->toBe('First Service');
});
