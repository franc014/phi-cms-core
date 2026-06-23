<?php

namespace JFA\PhiCmsCore\Schema;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use InvalidArgumentException;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;

final readonly class SchemaFieldRenderer
{
    public function render(Field $field): Component
    {
        return match (true) {
            $field instanceof StringField => $this->renderString($field),
            $field instanceof TextField => $this->renderText($field),
            $field instanceof SelectField => $this->renderSelect($field),
            $field instanceof RichField => $this->renderRich($field),
            $field instanceof RepeaterField => $this->renderRepeater($field),
            $field instanceof CustomField => $this->renderCustom($field),
            default => throw new InvalidArgumentException('Unsupported field type [' . get_class($field) . '].'),
        };
    }

    private function renderString(StringField $field): TextInput
    {
        $input = TextInput::make($field->name);

        if ($field->label !== null) {
            $input->label(__($field->label));
        }

        if ($field->required) {
            $input->required();
        }

        if ($field->max !== null) {
            $input->maxLength($field->max);
        }

        if ($field->live) {
            $input->live(debounce: 500);
        }

        return $input;
    }

    private function renderText(TextField $field): Textarea
    {
        $input = Textarea::make($field->name);

        if ($field->label !== null) {
            $input->label(__($field->label));
        }

        if ($field->required) {
            $input->required();
        }

        if ($field->max !== null) {
            $input->maxLength($field->max);
        }

        if ($field->live) {
            $input->live(debounce: 500);
        }

        return $input;
    }

    private function renderSelect(SelectField $field): Select
    {
        $select = Select::make($field->name)
            ->options($field->options);

        if ($field->label !== null) {
            $select->label(__($field->label));
        }

        if ($field->required) {
            $select->required();
        }

        if ($field->live) {
            $select->live(debounce: 500);
        }

        return $select;
    }

    private function renderRich(RichField $field): RichEditor
    {
        $editor = RichEditor::make($field->name);

        if ($field->label !== null) {
            $editor->label(__($field->label));
        }

        if ($field->required) {
            $editor->required();
        }

        if ($field->live) {
            $editor->live(debounce: 500);
        }

        return $editor;
    }

    private function renderRepeater(RepeaterField $field): Repeater
    {
        $repeater = Repeater::make($field->name)
            ->schema(array_map(
                fn (Field $child) => $this->render($child),
                $field->fields
            ));

        if ($field->label !== null) {
            $repeater->label(__($field->label));
        }

        if ($field->labelField !== null) {
            $labelField = $field->labelField;

            $repeater->itemLabel(fn (array $state): ?string => $state[$labelField] ?? null);
        }

        if ($field->min !== null) {
            $repeater->minItems($field->min);
        }

        return $repeater;
    }

    private function renderCustom(CustomField $field): Component
    {
        throw new InvalidArgumentException("Custom field handler [{$field->handler}] is not registered.");
    }
}
