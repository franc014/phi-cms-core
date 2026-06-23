<?php

namespace JFA\PhiCmsCore\Schema;

use InvalidArgumentException;
use JFA\PhiCmsCore\Schema\Fields\CustomField;
use JFA\PhiCmsCore\Schema\Fields\RepeaterField;
use JFA\PhiCmsCore\Schema\Fields\RichField;
use JFA\PhiCmsCore\Schema\Fields\SelectField;
use JFA\PhiCmsCore\Schema\Fields\StringField;
use JFA\PhiCmsCore\Schema\Fields\TextField;

final readonly class FieldFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $name, array $config): Field
    {
        $type = $config['type'] ?? null;

        if (! is_string($type)) {
            throw new InvalidArgumentException("Field [{$name}] must declare a string type.");
        }

        $live = (bool) ($config['live'] ?? false);

        return match ($type) {
            'string' => new StringField(
                name: $name,
                required: (bool) ($config['required'] ?? false),
                max: isset($config['max']) ? (int) $config['max'] : null,
                label: $config['label'] ?? null,
                live: $live,
            ),
            'text' => new TextField(
                name: $name,
                required: (bool) ($config['required'] ?? false),
                max: isset($config['max']) ? (int) $config['max'] : null,
                label: $config['label'] ?? null,
                live: $live,
            ),
            'select' => new SelectField(
                name: $name,
                required: (bool) ($config['required'] ?? false),
                options: $config['options'] ?? [],
                label: $config['label'] ?? null,
                live: $live,
            ),
            'rich' => new RichField(
                name: $name,
                required: (bool) ($config['required'] ?? false),
                label: $config['label'] ?? null,
                live: $live,
            ),
            'repeater' => new RepeaterField(
                name: $name,
                min: isset($config['min']) ? (int) $config['min'] : null,
                label: $config['label'] ?? null,
                labelField: $config['label_field'] ?? null,
                fields: $this->makeFields($config['fields'] ?? []),
            ),
            'posts_chooser', 'custom' => new CustomField(
                name: $name,
                handler: $config['handler'] ?? $type,
            ),
            default => throw new InvalidArgumentException("Unsupported field type [{$type}] for field [{$name}]."),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, Field>
     */
    public function makeFields(array $fields): array
    {
        $built = [];

        foreach ($fields as $name => $config) {
            if (! is_array($config)) {
                throw new InvalidArgumentException("Field [{$name}] configuration must be an array.");
            }

            $built[$name] = $this->make((string) $name, $config);
        }

        return $built;
    }
}
