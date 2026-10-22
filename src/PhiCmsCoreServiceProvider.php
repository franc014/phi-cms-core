<?php

namespace JFA\PhiCmsCore;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use JFA\PhiCmsCore\Commands\GenerateBlocksCommand;
use JFA\PhiCmsCore\Commands\SeedCommand;
use JFA\PhiCmsCore\Commands\ServicesCommand;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;
use JFA\PhiCmsCore\Http\Middleware\PrivatePreviewResponse;
use JFA\PhiCmsCore\Livewire\Preview;
use JFA\PhiCmsCore\Schema\FieldFactory;
use JFA\PhiCmsCore\Schema\Handlers\PostsChooserFieldHandler;
use JFA\PhiCmsCore\Schema\SchemaFieldRenderer;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use JFA\PhiCmsCore\Sections\SectionRenderer;
use JFA\PhiCmsCore\Testing\TestsPhiCmsCore;
use JFA\PhiCmsCore\View\Components\SectionView;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PhiCmsCoreServiceProvider extends PackageServiceProvider
{
    public static string $name = 'phi-cms-core';

    public static string $viewNamespace = 'phi-cms-core';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile('phi-cms')
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
                    ->publishMigrations()
                    ->publish('settings-migrations')
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command) {
                        if ($command->confirm('Would you like to seed Shield roles and default users?', false)) {
                            $command->call('vendor:publish', [
                                '--tag' => 'phi-cms-seed-config',
                                '--force' => true,
                            ]);

                            $command->call('db:seed', [
                                '--class' => 'JFA\PhiCmsCore\Database\Seeders\ShieldRoleSeeder',
                                '--force' => true,
                            ]);

                            $command->call('db:seed', [
                                '--class' => 'JFA\PhiCmsCore\Database\Seeders\ShieldUserSeeder',
                                '--force' => true,
                            ]);

                            $command->info('Shield roles and users seeded successfully.');
                        }

                        if ($command->confirm('Would you like to set up the Filament admin panel?', true)) {
                            $command->call('filament:install', [
                                '--panels' => true,
                                '--force' => true,
                                '--no-interaction' => true,
                            ]);
                        }
                    });
            });

        if (file_exists($package->basePath('/../config/phi-cms-schema.php'))) {
            $package->hasConfigFile(['phi-cms', 'phi-cms-schema']);
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

    public function packageRegistered(): void
    {
        $this->app->scoped(SectionRenderer::class);
        $this->app->singleton(SectionRegistry::class, fn () => new SectionRegistry(config('phi-cms.sections', [])));

        $this->app->singleton(SchemaRegistry::class, function (): SchemaRegistry {
            return SchemaRegistry::fromConfig(
                config('phi-cms-schema.blocks', []),
                new FieldFactory,
            );
        });

        $this->app->singleton(ContentBlockRegistry::class, function (): ContentBlockRegistry {
            $registry = new ContentBlockRegistry;
            $schemaRegistry = app(SchemaRegistry::class);

            foreach ($schemaRegistry->all() as $schema) {
                if ($schema->adminClass === null) {
                    continue;
                }

                $registry->register($schema->adminClass);
            }

            return $registry;
        });

        $this->app->singleton(SchemaFieldRenderer::class, function (): SchemaFieldRenderer {
            return new SchemaFieldRenderer([
                'posts_chooser' => app(PostsChooserFieldHandler::class),
            ]);
        });
    }

    public function packageBooted(): void
    {
        Blade::component(SectionView::class, 'phi-cms-core::section');
        Livewire::component('phi-cms-core.preview', Preview::class);
        $this->app['router']->pushMiddlewareToGroup('web', PrivatePreviewResponse::class);
        $mediaClass = config('media-library.media_model', Media::class);
        $mediaClass::saving([LegacySectionGuard::class, 'media']);
        $mediaClass::deleting([LegacySectionGuard::class, 'media']);

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
            $this->publishes([
                $this->package->basePath('/../config/phi-cms-schema.php') => config_path('phi-cms-schema.php'),
            ], 'phi-cms-schema-config');

            $seedConfigPath = $this->package->basePath('/../config/phi-cms-seed.php');
            if (file_exists($seedConfigPath)) {
                $this->publishes([
                    $seedConfigPath => config_path('phi-cms-seed.php'),
                ], 'phi-cms-seed-config');
            }

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
        return [
            GenerateBlocksCommand::class,
            SeedCommand::class,
            ServicesCommand::class,
        ];
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
            'create_metatags_table',
            'create_sections_table',
            'create_pages_table',
            'create_page_section_table',
            'create_menus_table',
            'create_menu_items_table',
            'create_posts_table',
            'create_categories_table',
            'create_category_post_table',
            'add_content_snapshots_to_sections_table',
        ];
    }
}
