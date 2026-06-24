<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class CustomField implements Field
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $name,
        public string $handler,
        public array $config = [],
    ) {}
}
