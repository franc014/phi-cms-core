<?php

namespace JFA\PhiCmsCore\Sections;

use Closure;

final class MediaMutation
{
    private static int $depth = 0;

    public static function run(Closure $operation): mixed
    {
        self::$depth++;

        try {
            return $operation();
        } finally {
            self::$depth--;
        }
    }

    public static function allowed(): bool
    {
        return self::$depth > 0;
    }
}
