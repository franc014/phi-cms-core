<?php

namespace JFA\PhiCmsCore\Pages;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class PageConflict extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'Another Page change was saved or the reviewed composition changed. Your input is retained. Reload latest explicitly, then review again.')
    {
        parent::__construct($message);
    }
}
