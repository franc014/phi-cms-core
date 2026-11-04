<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use JFA\PhiCmsCore\Menus\MenuContent;

class MenuInventoryCommand extends Command
{
    protected $signature = 'phi-cms:menus:inventory {key? : A developer-declared menu key}';

    protected $description = 'Read-only inventory of declared and stored PHI menus.';

    public function handle(MenuContent $menus): int
    {
        $keys = is_string($this->argument('key'))
            ? [$this->argument('key')]
            : array_keys(config('phi-cms.navigation.menus', []));

        if ($keys === []) {
            $this->warn('No developer-declared menus are configured.');

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            $this->line(json_encode($menus->inventory($key), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
