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
}
