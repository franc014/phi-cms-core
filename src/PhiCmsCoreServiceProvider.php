<?php

namespace JFA\PhiCmsCore;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use JFA\PhiCmsCore\Testing\TestsPhiCmsCore;
use Livewire\Features\SupportTesting\Testable;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PhiCmsCoreServiceProvider extends PackageServiceProvider
{
    public static string $name = 'phi-cms-core';

    public static string $viewNamespace = 'phi-cms-core';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->startWith(function (InstallCommand $command) {
                        $command->comment('Publishing Spatie Media Library migrations...');
                        $command->call('vendor:publish', [
                            '--provider' => 'Spatie\MediaLibrary\MediaLibraryServiceProvider',
                            '--tag' => 'medialibrary-migrations',
                        ]);

                        $command->comment('Publishing Spatie Tags migrations...');
                        $command->call('vendor:publish', [
                            '--provider' => 'Spatie\Tags\TagsServiceProvider',
                            '--tag' => 'tags-migrations',
                        ]);

                        $command->comment('Publishing Spatie Settings migrations...');
                        $command->call('vendor:publish', [
                            '--provider' => 'Spatie\LaravelSettings\LaravelSettingsServiceProvider',
                            '--tag' => 'migrations',
                        ]);
                    })
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->publish('settings-migrations')
                    ->askToRunMigrations();
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void {}

    public function packageBooted(): void
    {
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        FilamentIcon::register($this->getIcons());

        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/phi-cms-core/{$file->getFilename()}"),
                ], 'phi-cms-core-stubs');
            }

            $settingsPath = __DIR__ . '/../database/settings';
            if (is_dir($settingsPath)) {
                $this->publishes([
                    $settingsPath => database_path('settings'),
                ], 'phi-cms-core-settings-migrations');
            }
        }

        Testable::mixin(new TestsPhiCmsCore);
    }

    protected function getAssetPackageName(): ?string
    {
        return 'franc014/phi-cms-core';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getIcons(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getRoutes(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            'create_sections_table',
            'create_pages_table',
            'create_page_section_table',
            'create_menus_table',
            'create_menu_items_table',
            'create_posts_table',
            'create_categories_table',
            'create_category_post_table',
        ];
    }
}
