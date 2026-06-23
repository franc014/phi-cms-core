<?php

namespace JFA\PhiCmsCore\Schema;

use InvalidArgumentException;

final readonly class SchemaRegistry
{
    /**
     * @param  array<string, Schema>  $schemas
     */
    public function __construct(private array $schemas) {}

    /**
     * @param  array<string, array<string, mixed>>  $config
     */
    public static function fromConfig(array $config, FieldFactory $factory): self
    {
        $schemas = [];

        foreach ($config as $type => $blockConfig) {
            if (! is_array($blockConfig)) {
                throw new InvalidArgumentException("Schema configuration for block [{$type}] must be an array.");
            }

            $fields = $factory->makeFields($blockConfig['fields'] ?? []);

            $schemas[$type] = new Schema(
                type: (string) $type,
                adminClass: $blockConfig['admin']['class'] ?? null,
                dtoClass: $blockConfig['dto'] ?? null,
                adminMeta: $blockConfig['admin'] ?? [],
                fields: $fields,
            );
        }

        return new self($schemas);
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->schemas);
    }

    public function get(string $type): Schema
    {
        if (! $this->has($type)) {
            throw new InvalidArgumentException("No schema found for block type [{$type}].");
        }

        return $this->schemas[$type];
    }

    public function typeForAdminClass(string $class): string
    {
        foreach ($this->schemas as $type => $schema) {
            if ($schema->adminClass === $class) {
                return $type;
            }
        }

        throw new InvalidArgumentException("No schema found for admin block class [{$class}].");
    }

    public function typeForDtoClass(string $class): string
    {
        foreach ($this->schemas as $type => $schema) {
            if ($schema->dtoClass === $class) {
                return $type;
            }
        }

        throw new InvalidArgumentException("No schema found for DTO class [{$class}].");
    }

    /**
     * @return array<string, Schema>
     */
    public function all(): array
    {
        return $this->schemas;
    }
}
