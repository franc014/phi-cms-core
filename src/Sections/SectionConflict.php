<?php

namespace JFA\PhiCmsCore\Sections;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class SectionConflict extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Another change was saved or the affected usages changed. Your input is retained. Reload latest explicitly, then review again.');
    }
}
