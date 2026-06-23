<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class StringField implements Field
{
    public function __construct(
        public string $name,
        public bool $required,
        public ?int $max,
        public ?string $label,
        public bool $live = false,
    ) {}
}
