<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class SelectField implements Field
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $name,
        public bool $required,
        public array $options,
        public ?string $label,
        public bool $live = false,
    ) {}
}
