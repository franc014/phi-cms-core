<?php

namespace JFA\PhiCmsCore\Schema\Fields;

use JFA\PhiCmsCore\Schema\Field;

final readonly class CustomField implements Field
{
    public function __construct(
        public string $name,
        public string $handler,
    ) {}
}
