<?php

namespace JFA\PhiCmsCore\Sections;

use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JFA\PhiCmsCore\Forms\Components\Image;

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
        return self::collectImages(static::fields(), $content, 'content');
    }

    /**
     * @param  array<Component>  $fields
     * @param  array<string, mixed>  $content
     * @return list<array{path: string, reference: string, required: bool}>
     */
    private static function collectImages(array $fields, array $content, string $prefix): array
    {
        $references = [];
        foreach ($fields as $field) {
            if ($field instanceof Image) {
                $value = data_get($content, $field->getName());
                if ($value !== null && (! is_string($value) || trim($value) === '')) {
                    throw ValidationException::withMessages([$prefix . '.' . $field->getName() => 'The image selection must be a nonempty reference or null.']);
                }
                $references[] = ['path' => $prefix . '.' . $field->getName(), 'reference' => is_string($value) ? $value : '', 'required' => $field->isRequired()];
            } elseif ($field instanceof Repeater) {
                if ($field->getRelationshipName() !== null) {
                    throw new InvalidArgumentException('Relationship repeaters are not supported in Section definitions.');
                }
                $children = $field->getDefaultChildComponents();
                if (! is_array($children)) {
                    throw new InvalidArgumentException('Section image discovery requires a static native repeater schema.');
                }
                foreach (data_get($content, $field->getName(), []) ?? [] as $key => $item) {
                    $references = [...$references, ...self::collectImages($children, is_array($item) ? $item : [], $prefix . '.' . $field->getName() . '.' . $key)];
                }
            } elseif ($field->getDefaultChildComponents() !== []) {
                throw new InvalidArgumentException('Section image discovery supports explicit Image fields and ordinary repeaters only.');
            }
        }

        return $references;
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
