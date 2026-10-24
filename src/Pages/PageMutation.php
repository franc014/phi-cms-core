<?php

namespace JFA\PhiCmsCore\Pages;

use Closure;

final class PageMutation
{
    private static int $depth = 0;

    public static function allowed(): bool
    {
        return self::$depth > 0;
    }

    public static function run(Closure $operation): mixed
    {
        self::$depth++;

        try {
            return $operation();
        } finally {
            self::$depth--;
        }
    }
}
