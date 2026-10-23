<?php

namespace JFA\PhiCmsCore\Sections;

use Filament\Schemas\Components\Component;

abstract class SectionDefinition
{
    abstract public static function key(): string;

    abstract public static function view(): string;

    /** @return array<Component> */
    abstract public static function fields(): array;

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    abstract public static function normalize(array $content): array;

    /**
     * Return image references declared by this definition.
     *
     * Definitions that do not own Image fields return an empty list. Image
     * fields are deliberately explicit so arbitrary JSON cannot become a
     * media selection by convention.
     *
     * @param  array<string, mixed>  $content
     * @return list<array{path: string, reference: string, required: bool}>
     */
    public static function imageReferences(array $content): array
    {
        return [];
    }

    /**
     * Convert the legacy Section payload to the canonical snapshot payload.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function adoptLegacy(array $content): array
    {
        return static::normalize($content);
    }

    /**
     * Describe legacy references that must remain available after adoption.
     *
     * @param  array<string, mixed>  $content
     * @return list<array{reference: string, url: string}>
     */
    public static function legacyImageSources(array $content): array
    {
        return [];
    }

    /**
     * Convert a canonical snapshot back to the legacy payload shape.
     *
     * Definitions may refuse this operation when the legacy format cannot
     * represent the current snapshot without changing public output.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function toLegacy(array $content): array
    {
        return static::normalize($content);
    }
}
