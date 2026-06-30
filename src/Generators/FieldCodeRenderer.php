<?php

namespace JFA\PhiCmsCore\Generators;

use Illuminate\Support\Str;
use JFA\PhiCmsCore\Schema\Field;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\Fields\ImageField;
use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;
use JFA\PhiCmsCore\Schema\Schema;

final class FieldCodeRenderer
{
    public function renderField(Schema $schema, Field $field): FieldCode
    {
        return match (true) {
            $field instanceof StringField, $field instanceof TextField, $field instanceof SelectField => $this->renderStringField($schema, $field),
            $field instanceof ImageField => $this->renderImageField($schema, $field),
            $field instanceof RichField => $this->renderRichField($schema, $field),
            $field instanceof CustomField => $this->renderCustomField($schema, $field),
            default => throw new \InvalidArgumentException('Unsupported schema field encountered.'),
        };
    }

    public function renderRepeaterChildField(Schema $schema, RepeaterField $parentField, Field $field): FieldCode
    {
        return match (true) {
            $field instanceof StringField, $field instanceof TextField, $field instanceof SelectField => $this->renderRepeaterScalarField($schema, $parentField, $field),
            $field instanceof ImageField => $this->renderRepeaterImageField($schema, $parentField, $field),
            default => throw new \InvalidArgumentException('Unsupported repeater field encountered.'),
        };
    }

    public function renderRepeaterValidation(Schema $schema, RepeaterField $field): string
    {
        $messagePrefix = $this->blockMessagePrefix($schema);

        return sprintf(
            <<<'PHP'
        if (! array_key_exists('%s', $data)) {
            throw new InvalidArgumentException('%s block requires [%s].');
        }

        $raw%s = $data['%s'];

        if (! is_array($raw%s)) {
            throw new InvalidArgumentException('%s block [%s] must be an array.');
        }

        $%s = [];

        foreach ($raw%s as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each %s item must be an array.');
            }

            $%s[] = %s::fromArray($item);
        }
PHP,
            $field->name,
            $messagePrefix,
            $field->name,
            ucfirst($field->name),
            $field->name,
            ucfirst($field->name),
            $messagePrefix,
            $field->name,
            $field->name,
            ucfirst($field->name),
            Str::singular($schema->type),
            $field->name,
            (new self)->repeaterItemClassName($schema->type, $field->name),
        );
    }

    public function repeaterItemClassName(string $blockType, string $fieldName): string
    {
        return Str::studly(Str::singular($blockType)) . Str::studly(Str::singular($fieldName)) . 'Data';
    }

    public function blockMessagePrefix(Schema $schema): string
    {
        return Str::headline($schema->type);
    }

    private function renderStringField(Schema $schema, StringField | TextField | SelectField $field): FieldCode
    {
        $property = Str::camel($field->name);
        $type = $field->required ? 'string' : '?string';
        $prefix = $this->blockMessagePrefix($schema);

        $validation = $field->required
            ? $this->requiredStringValidation($field->name, $prefix)
            : $this->optionalStringValidation($field->name, $prefix);

        $assignment = $field->required
            ? "\$data['{$field->name}']"
            : "\$data['{$field->name}'] ?? null";

        $arrayLine = "            '{$field->name}' => \$this->{$property},";
        $viewLine = $arrayLine;

        return new FieldCode($property, $type, $validation, $arrayLine, $viewLine, $assignment);
    }

    private function renderImageField(Schema $schema, ImageField $field): FieldCode
    {
        $property = Str::camel($field->name);
        $type = $field->required ? 'string' : '?string';
        $prefix = $this->blockMessagePrefix($schema);

        $validation = $field->required
            ? $this->requiredStringValidation($field->name, $prefix)
            : $this->optionalStringValidation($field->name, $prefix);

        $assignment = $field->required
            ? "\$data['{$field->name}']"
            : "\$data['{$field->name}'] ?? null";

        $arrayLine = "            '{$field->name}' => \$this->{$property},";
        $viewLine = $arrayLine;

        return new FieldCode($property, $type, $validation, $arrayLine, $viewLine, $assignment);
    }

    private function renderRichField(Schema $schema, RichField $field): FieldCode
    {
        $property = Str::camel($field->name);
        $type = $field->required ? 'array|string' : 'array|string|null';
        $messagePrefix = $this->blockMessagePrefix($schema);

        $validation = sprintf(
            <<<'PHP'
        if (! array_key_exists('%s', $data)) {
            throw new InvalidArgumentException('%s block requires [%s].');
        }

        if (! is_string($data['%s']) && ! is_array($data['%s'])) {
            throw new InvalidArgumentException('%s block [%s] must be a string or array.');
        }
PHP,
            $field->name,
            $messagePrefix,
            $field->name,
            $field->name,
            $field->name,
            $messagePrefix,
            $field->name,
        );

        if (! $field->json) {
            $validation .= sprintf(
                "\n\n        if (is_array(\$data['%s']) && ! isset(\$data['%s']['type'])) {\n            throw new InvalidArgumentException('%s block [%s] as array must have a [type] key.');\n        }",
                $field->name,
                $field->name,
                $messagePrefix,
                $field->name,
            );
        }

        $assignment = $field->required
            ? "\$data['{$field->name}']"
            : "\$data['{$field->name}'] ?? null";

        $arrayLine = "            '{$field->name}' => \$this->{$property},";
        $viewLine = $arrayLine;

        return new FieldCode($property, $type, $validation, $arrayLine, $viewLine, $assignment);
    }

    private function renderCustomField(Schema $schema, CustomField $field): FieldCode
    {
        $property = Str::camel($field->name);

        if ($field->handler === 'posts_chooser') {
            $validation = sprintf(
                <<<'PHP'
        if (! array_key_exists('%s', $data)) {
            throw new InvalidArgumentException('%s block requires [%s].');
        }

        $%s = is_array($data['%s']) ? $data['%s'] : [];

        $%s = array_map(fn ($id) => (int) $id, $%s);
PHP,
                $field->name,
                $this->blockMessagePrefix($schema),
                $field->name,
                $property,
                $field->name,
                $field->name,
                $property,
                $property,
            );

            $arrayLine = "            '{$field->name}' => \$this->{$property},";
            $viewLine = $arrayLine;

            return new FieldCode($property, 'array', $validation, $arrayLine, $viewLine, "\${$property}");
        }

        $validation = sprintf(
            <<<'PHP'
        if (! array_key_exists('%s', $data)) {
            throw new InvalidArgumentException('%s block requires [%s].');
        }
PHP,
            $field->name,
            $this->blockMessagePrefix($schema),
            $field->name,
        );

        $arrayLine = "            '{$field->name}' => \$this->{$property},";
        $viewLine = $arrayLine;

        return new FieldCode($property, 'array', $validation, $arrayLine, $viewLine, "\$data['{$field->name}']");
    }

    private function renderRepeaterScalarField(Schema $schema, RepeaterField $parentField, StringField | TextField | SelectField $field): FieldCode
    {
        $property = Str::camel($field->name);
        $type = $field->required ? 'string' : '?string';
        $messagePrefix = Str::headline($schema->type) . ' ' . Str::singular($parentField->name);

        $validation = $field->required
            ? sprintf(
                <<<'PHP'
        if (! array_key_exists('%s', $data) || ! is_string($data['%s']) || $data['%s'] === '') {
            throw new InvalidArgumentException('%s requires a non-empty string [%s].');
        }
PHP,
                $field->name,
                $field->name,
                $field->name,
                $messagePrefix,
                $field->name,
            )
            : sprintf(
                <<<'PHP'
        if (array_key_exists('%s', $data) && $data['%s'] !== null && ! is_string($data['%s'])) {
            throw new InvalidArgumentException('%s requires a string [%s].');
        }
PHP,
                $field->name,
                $field->name,
                $field->name,
                $messagePrefix,
                $field->name,
            );

        $assignment = $field->required
            ? "\$data['{$field->name}']"
            : "\$data['{$field->name}'] ?? null";

        $arrayLine = "            '{$field->name}' => \$this->{$property},";

        return new FieldCode($property, $type, $validation, $arrayLine, $arrayLine, $assignment);
    }

    private function renderRepeaterImageField(Schema $schema, RepeaterField $parentField, ImageField $field): FieldCode
    {
        $property = Str::camel($field->name);
        $type = $field->required ? 'string' : '?string';
        $messagePrefix = Str::headline($schema->type) . ' ' . Str::singular($parentField->name);

        $validation = $field->required
            ? sprintf(
                <<<'PHP'
        if (! array_key_exists('%s', $data) || ! is_string($data['%s']) || $data['%s'] === '') {
            throw new InvalidArgumentException('%s requires a non-empty string [%s].');
        }
PHP,
                $field->name,
                $field->name,
                $field->name,
                $messagePrefix,
                $field->name,
            )
            : sprintf(
                <<<'PHP'
        if (array_key_exists('%s', $data) && $data['%s'] !== null && ! is_string($data['%s'])) {
            throw new InvalidArgumentException('%s requires a string [%s].');
        }
PHP,
                $field->name,
                $field->name,
                $field->name,
                $messagePrefix,
                $field->name,
            );

        $assignment = $field->required
            ? "\$data['{$field->name}']"
            : "\$data['{$field->name}'] ?? null";

        $arrayLine = "            '{$field->name}' => \$this->{$property},";

        return new FieldCode($property, $type, $validation, $arrayLine, $arrayLine, $assignment);
    }

    private function requiredStringValidation(string $fieldName, string $prefix): string
    {
        return sprintf(
            <<<'PHP'
        if (! array_key_exists('%s', $data) || ! is_string($data['%s']) || $data['%s'] === '') {
            throw new InvalidArgumentException('%s block requires a non-empty string [%s].');
        }
PHP,
            $fieldName,
            $fieldName,
            $fieldName,
            $prefix,
            $fieldName,
        );
    }

    private function optionalStringValidation(string $fieldName, string $prefix): string
    {
        return sprintf(
            <<<'PHP'
        if (array_key_exists('%s', $data) && $data['%s'] !== null && ! is_string($data['%s'])) {
            throw new InvalidArgumentException('%s block requires a string [%s].');
        }
PHP,
            $fieldName,
            $fieldName,
            $fieldName,
            $prefix,
            $fieldName,
        );
    }
}
