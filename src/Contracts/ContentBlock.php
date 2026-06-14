<?php

namespace JFA\PhiCmsCore\Contracts;

use Filament\Forms\Components\Builder\Block;

interface ContentBlock
{
    public static function make(): Block;
}
