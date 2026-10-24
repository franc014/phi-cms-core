<?php

namespace JFA\PhiCmsCore;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use JFA\PhiCmsCore\Commands\GalleryCommand;
use JFA\PhiCmsCore\Commands\GalleryDeleteMediaCommand;
use JFA\PhiCmsCore\Commands\GenerateBlocksCommand;
use JFA\PhiCmsCore\Commands\GuardedCleanCommand;
use JFA\PhiCmsCore\Commands\PageCommand;
use JFA\PhiCmsCore\Commands\SeedCommand;
use JFA\PhiCmsCore\Commands\ServicesCommand;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;
use JFA\PhiCmsCore\Http\Middleware\AuthorizeSectionTemporaryPreview;
use JFA\PhiCmsCore\Http\Middleware\PrivatePreviewResponse;
use JFA\PhiCmsCore\Livewire\Preview;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Pages\PageRegistry;
use JFA\PhiCmsCore\Schema\FieldFactory;
use JFA\PhiCmsCore\Schema\Handlers\PostsChooserFieldHandler;
use JFA\PhiCmsCore\Schema\SchemaFieldRenderer;
use JFA\PhiCmsCore\Schema\SchemaRegistry;
use JFA\PhiCmsCore\Sections\GuardedFileManipulator;
use JFA\PhiCmsCore\Sections\GuardedResponsiveImageGenerator;
use JFA\PhiCmsCore\Sections\LegacySectionGuard;
use JFA\PhiCmsCore\Sections\SectionImageContext;
use JFA\PhiCmsCore\Sections\SectionImages;
use JFA\PhiCmsCore\Sections\SectionRegistry;
use JFA\PhiCmsCore\Sections\SectionRenderer;
use JFA\PhiCmsCore\Sections\SectionTemporaryFileSynth;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use JFA\PhiCmsCore\Testing\TestsPhiCmsCore;
use JFA\PhiCmsCore\View\Components\SectionView;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Commands\CleanCommand;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;

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
        $this->app->bind(FileManipulator::class, GuardedFileManipulator::class);
        $this->app->bind(ResponsiveImageGenerator::class, GuardedResponsiveImageGenerator::class);
        $this->app->bind(CleanCommand::class, GuardedCleanCommand::class);
        $this->app->scoped(SectionRenderer::class);
        $this->app->scoped(SectionImageContext::class);
        $this->app->singleton(SectionImages::class);
        $this->app->singleton(SectionRegistry::class, fn () => new SectionRegistry(config('phi-cms.sections', [])));
        $this->app->singleton(PageRegistry::class, fn (): PageRegistry => new PageRegistry(
            config('phi-cms.pages', []),
            app(SectionRegistry::class),
        ));
        $this->app->scoped(PageContent::class);

        if (config('media-library.media_model') === Media::class) {
            config(['media-library.media_model' => Models\Media::class]);
        }

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
        $this->app->booted(static function (): void {
            Livewire::propertySynthesizer(SectionTemporaryFileSynth::class);
        });
        \Livewire\on('call', static function (Component $component, string $method, array $params): void {
            if (! in_array($method, ['_finishUpload', '_removeUpload'], true)) {
                return;
            }
            foreach ((array) ($params[1] ?? []) as $value) {
                if (! is_string($value)) {
                    continue;
                }
                $filename = TemporaryUploadedFile::extractPathFromSignedPath($value);
                $filename = $filename === false ? $value : $filename;
                if (str_starts_with($filename, 'phi-')) {
                    app(SectionTemporaryUploads::class)->file($filename, true, $component->getId(), $params[0] ?? null);
                }
            }
        });
        $this->app['router']->pushMiddlewareToGroup('web', PrivatePreviewResponse::class);
        if (! in_array(AuthorizeSectionTemporaryPreview::class, FilePreviewController::$middleware, true)) {
            FilePreviewController::$middleware[] = AuthorizeSectionTemporaryPreview::class;
        }

        if (in_array(config('media-library.media_model'), [null, Media::class], true)) {
            config(['media-library.media_model' => Models\Media::class]);
        }

        $mediaClass = config('media-library.media_model', Media::class);
        $mediaClass::observe($this->app->make(MediaObserver::class));
        $mediaClass::saving([LegacySectionGuard::class, 'media']);
        $mediaClass::deleting([LegacySectionGuard::class, 'media']);
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

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
            GalleryCommand::class,
            GalleryDeleteMediaCommand::class,
            PageCommand::class,
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
            'add_gallery_image_sources_to_sections_table',
            'create_page_urls_table',
        ];
    }
}
