<?php

namespace JFA\PhiCmsCore\Pages;

abstract class PageTemplate
{
    abstract public static function key(): string;

    abstract public static function view(): string;

    /**
     * @return array{
     *     mode: string,
     *     allowed: list<string>,
     *     required: list<string>,
     *     slots?: array<string, array{type: string, required?: bool, selectable?: bool}>
     * }
     */
    abstract public static function composition(): array;
}
