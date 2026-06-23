<?php

namespace JFA\PhiCmsCore\Schema;

use JFA\PhiCmsCore\Schema\Fields\RepeaterField;

final readonly class Schema
{
    /**
     * @param  array<string, mixed>  $adminMeta
     * @param  array<string, Field>  $fields
     */
    public function __construct(
        public string $type,
        public ?string $adminClass,
        public ?string $dtoClass,
        public array $adminMeta,
        public array $fields,
    ) {}

    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    public function isRepeatable(string $name): bool
    {
        $field = $this->field($name);

        return $field instanceof RepeaterField;
    }
}
