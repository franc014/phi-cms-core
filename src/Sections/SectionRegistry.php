<?php

namespace JFA\PhiCmsCore\Sections;

use Filament\Schemas\Components\Component;
use InvalidArgumentException;

final class SectionRegistry
{
    /** @var array<string, class-string<SectionDefinition>> */
    private array $definitions = [];

    /** @param array<mixed> $definitions */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $definition) {
            if (! is_string($definition) || ! is_subclass_of($definition, SectionDefinition::class)) {
                throw new InvalidArgumentException('Invalid Section definition.');
            }

            $key = $definition::key();

            if (! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $key) || isset($this->definitions[$key])) {
                throw new InvalidArgumentException("Invalid or duplicate Section key: {$key}.");
            }

            if (! view()->exists($definition::view())) {
                throw new InvalidArgumentException("Missing Blade view for Section definition: {$key}.");
            }

            /** @var array<mixed> $fields */
            $fields = $definition::fields();
            foreach ($fields as $field) {
                if (! $field instanceof Component) {
                    throw new InvalidArgumentException("Invalid native field in Section definition: {$key}.");
                }
            }

            $this->definitions[$key] = $definition;
        }
    }

    /** @return class-string<SectionDefinition> */
    public function get(string $key): string
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException("Missing Section definition: {$key}.");
    }
}
