<?php

namespace JFA\PhiCmsCore;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Tables\Actions\DissociateAction;
use JFA\PhiCmsCore\Forms\Blocks\ContentBlockRegistry;
use JFA\PhiCmsCore\Pages\ManageCompany;
use JFA\PhiCmsCore\Resources\Categories\CategoryResource;
use JFA\PhiCmsCore\Resources\Menus\MenuResource;
use JFA\PhiCmsCore\Resources\Pages\PageResource;
use JFA\PhiCmsCore\Resources\Posts\PostResource;
use JFA\PhiCmsCore\Resources\Sections\SectionResource;

class PhiCmsCorePlugin implements Plugin
{
    public function getId(): string
    {
        return 'phi-cms-core';
    }

    public function register(Panel $panel): void
    {
        app()->singleton(ContentBlockRegistry::class);

        $panel
            ->resources([
                CategoryResource::class,
                MenuResource::class,
                PageResource::class,
                PostResource::class,
                SectionResource::class,
            ])
            ->pages([
                ManageCompany::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        $this->registerContentBlocks();
        $this->configureFilament();
    }

    protected function registerContentBlocks(): void
    {
        $registry = app(ContentBlockRegistry::class);

        $config = config('phi-cms-core.content_blocks', []);

        foreach ($config['default'] ?? [] as $block) {
            $registry->registerDefault($block);
        }

        foreach ($config['custom'] ?? [] as $block) {
            $registry->registerCustom($block);
        }
    }

    protected function configureFilament(): void
    {
        Color::configureUsing(fn ($color) => $color === null, true);

        $panel = Action::configureUsing(
            fn (Action $action) => $action
                ->slideOver()
                ->modalWidth('6xl')
        );

        ReplicateAction::configureUsing(
            fn (ReplicateAction $action) => $action
                ->successNotificationTitle('Record replicated')
        );

        EditAction::configureUsing(
            fn (EditAction $action) => $action
                ->successNotificationTitle('Record saved')
        );

        DeleteAction::configureUsing(
            fn (DeleteAction $action) => $action
                ->successNotificationTitle('Record deleted')
        );

        DissociateAction::configureUsing(
            fn (DissociateAction $action) => $action
                ->successNotificationTitle('Record dissociated')
        );
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
