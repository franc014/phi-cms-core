<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class RichField implements Field
{
    /**
     * @param  array<int, class-string>  $customBlocks
     */
    public function __construct(
        public string $name,
        public bool $required,
        public ?string $label,
        public bool $live = false,
        public array $customBlocks = [],
        public bool $json = false,
    ) {}
}
