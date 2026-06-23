<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class RepeaterField implements Field
{
    /**
     * @param  array<string, Field>  $fields
     */
    public function __construct(
        public string $name,
        public ?int $min,
        public ?string $label,
        public ?string $labelField,
        public array $fields,
    ) {}
}
