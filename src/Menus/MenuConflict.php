<?php

namespace JFA\PhiCmsCore\Menus;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class MenuConflict extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'The menu changed or a reviewed destination changed. Reload the saved menu and review it again.')
    {
        parent::__construct($message);
    }
}
