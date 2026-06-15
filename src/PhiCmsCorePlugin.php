<?php

namespace JFA\PhiCmsCore;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Icons\Heroicon;
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

    public function configureFilament(): void
    {
        FilamentColor::register([
            'accent' => Color::Cyan,
        ]);

        Action::configureUsing(function (Action $action) {
            return $action->slideOver()->modalWidth('6xl');
        });
        ReplicateAction::configureUsing(function (ReplicateAction $action) {
            return $action->color('info')->modalHeading(__('You are about to duplicate this item. Are you sure?'))
                ->slideOver(false)
                ->modalIcon(Heroicon::ExclamationTriangle)
                ->modalWidth('2xl');
        });
        EditAction::configureUsing(function (EditAction $action) {
            return $action->color('success');
        });

        DeleteAction::configureUsing(function (DeleteAction $action) {
            return $action->slideOver(false)->modalWidth('2xl');
        });
        DetachAction::configureUsing(function (DetachAction $action) {
            return $action->slideOver(false)->modalWidth('2xl');
        });
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
