<?php

namespace JFA\PhiCmsCore\Forms\Blocks\Concerns;

use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;
use JFA\PhiCmsCore\Schema\SchemaFieldRenderer;
use JFA\PhiCmsCore\Schema\SchemaRegistry;

trait RendersSchemaFields
{
    /**
     * Render every field defined in the schema for the given block type and
     * return the Filament components keyed by field name.
     *
     * @return array<string, Component>
     */
    protected static function schemaComponents(string $type): array
    {
        $schema = app(SchemaRegistry::class)->get($type);

        return Collection::make($schema->fields)
            ->map(fn ($field) => app(SchemaFieldRenderer::class)->render($field))
            ->keyBy(fn (Component $component) => $component->getName())
            ->all();
    }
}
