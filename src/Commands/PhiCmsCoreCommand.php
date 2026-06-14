<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;

class PhiCmsCoreCommand extends Command
{
    public $signature = 'phi-cms-core';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
