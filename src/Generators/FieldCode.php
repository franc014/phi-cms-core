<?php

namespace JFA\PhiCmsCore\Generators;

final readonly class FieldCode
{
    public function __construct(
        public string $property,
        public string $type,
        public string $validation,
        public string $arrayLine,
        public string $viewLine,
        public string $assignment,
    ) {}
}
