<?php

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use JFA\PhiCmsCore\Menus\MenuContent;
use Throwable;

class MenuAdoptCommand extends Command
{
    protected $signature = 'phi-cms:menus:adopt {key : A developer-declared menu key} {--apply : Persist the validated adoption or reconciliation} {--reconcile : Validate additive declaration changes without resetting menu items}';

    protected $description = 'Preflight and explicitly adopt a developer-declared PHI menu.';

    public function handle(MenuContent $menus): int
    {
        try {
            $preflight = $menus->preflight((string) $this->argument('key'), reconcile: (bool) $this->option('reconcile'));
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
            $this->info($this->option('reconcile')
                ? 'Write-free reconciliation preflight complete. Re-run with --reconcile --apply to accept declarations and invalidate old reviews; menu items stay unchanged.'
                : 'Write-free preflight complete. Re-run with --apply to create equal draft and public rows.');

            return self::SUCCESS;
        }

        try {
            if ($this->option('reconcile')) {
                $version = $menus->reconcileConfiguredMenu((string) $this->argument('key'), $preflight['version']);
                $this->info("Reconciled menu [{$preflight['key']}] at version {$version}. Review the saved draft again before publishing.");

                return self::SUCCESS;
            }
            $menu = $menus->adoptConfiguredMenu((string) $this->argument('key'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Adopted menu [{$menu->key}] at version {$menu->menu_version}.");

        return self::SUCCESS;
    }
}
