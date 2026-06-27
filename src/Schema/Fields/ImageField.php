<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class ImageField implements Field
{
    public function __construct(
        public string $name,
        public bool $required,
        public ?string $label,
    ) {}
}
