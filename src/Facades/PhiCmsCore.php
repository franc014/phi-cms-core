<?php

namespace JFA\PhiCmsCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JFA\PhiCmsCore\PhiCmsCore
 */
class PhiCmsCore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JFA\PhiCmsCore\PhiCmsCore::class;
    }
}
