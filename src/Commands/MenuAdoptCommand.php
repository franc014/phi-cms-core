<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use JFA\PhiCmsCore\Menus\MenuContent;
use Throwable;

class MenuAdoptCommand extends Command
{
    protected $signature = 'phi-cms:menus:adopt {key : A developer-declared menu key} {--apply : Persist the validated initial draft and public list}';

    protected $description = 'Preflight and explicitly adopt a developer-declared PHI menu.';

    public function handle(MenuContent $menus): int
    {
        try {
            $preflight = $menus->preflight((string) $this->argument('key'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! $preflight['publishable']) {
            $this->error('Preflight found unavailable destinations. Nothing was written.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Write-free preflight complete. Re-run with --apply to create equal draft and public rows.');

            return self::SUCCESS;
        }

        try {
            $menu = $menus->adoptConfiguredMenu((string) $this->argument('key'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Adopted menu [{$menu->key}] at version {$menu->menu_version}.");

        return self::SUCCESS;
    }
}
