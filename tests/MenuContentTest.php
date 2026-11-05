<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Menus\MenuConflict;
use JFA\PhiCmsCore\Menus\MenuContent;
use JFA\PhiCmsCore\Models\Menu;
use JFA\PhiCmsCore\Models\MenuItem;
use JFA\PhiCmsCore\Resources\Menus\MenuResource;
use JFA\PhiCmsCore\Resources\Menus\Pages\EditMenu;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function prepareMenuContentTest(): void
{
    config([
        'phi-cms.editing' => true,
        'phi-cms.navigation' => [
            'menus' => [
                'header' => [
                    'label' => 'Header',
                    'adoption_items' => [
                        [
                            'label' => 'Specimens',
                            'destination' => [
                                'kind' => 'page_slug',
                                'page_slug' => 'home',
                                'anchor' => 'specimens',
                            ],
                        ],
                    ],
                ],
            ],
            'page_route' => 'pages.show',
            'page_route_parameter' => 'slug',
            'fixed_pages' => [
                'home' => [
                    'page_slug' => 'home',
                    'route' => 'home',
                    'anchors' => [
                        'specimens' => [
                            'label' => 'Specimens',
                            'section_slug' => 'home-specimens',
                        ],
                    ],
                ],
            ],
            'fixed_links' => [
                'home' => [
                    'label' => 'Home',
                    'route' => 'home',
                ],
            ],
        ],
    ]);

    Route::get('/', static fn (): string => 'Home')->name('home');
    Route::get('/pages/{slug}', static fn (string $slug): string => $slug)->name('pages.show');
    Route::getRoutes()->refreshNameLookups();

    Schema::create('menus', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('description');
        $table->string('slug');
        $table->string('key')->nullable()->unique();
        $table->unsignedBigInteger('menu_version')->nullable();
        $table->json('adoption_bindings')->nullable();
        $table->timestamps();
    });

    Schema::create('menu_items', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->string('label');
        $table->string('url');
        $table->json('items')->nullable();
        $table->unsignedInteger('order_column')->default(0);
        $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
        $table->string('stage')->default('published');
        $table->uuid('item_key')->nullable();
        $table->json('destination')->nullable();
        $table->timestamps();
        $table->unique(['menu_id', 'stage', 'item_key']);
    });

    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('status');
        $table->string('route');
        $table->timestamp('published_at')->nullable();
        $table->string('template')->nullable();
        $table->json('draft_fields')->nullable();
        $table->unsignedBigInteger('page_version')->nullable();
        $table->timestamps();
    });

    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('type');
        $table->string('status');
        $table->json('content')->nullable();
        $table->json('draft_content')->nullable();
        $table->json('published_content')->nullable();
        $table->unsignedBigInteger('content_version')->nullable();
        $table->json('legacy_image_sources')->nullable();
        $table->timestamps();
    });

    Schema::create('page_section', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('page_id');
        $table->foreignId('section_id');
        $table->unsignedInteger('order_column');
        $table->string('stage')->default('published');
        $table->uuid('occurrence_id')->nullable();
        $table->string('slot')->nullable();
        $table->timestamps();
    });

    Schema::create('page_urls', function (Blueprint $table): void {
        $table->id();
        $table->string('slug')->unique();
        $table->foreignId('page_id');
        $table->string('kind');
        $table->timestamps();
    });

    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Menu editor',
        'email' => 'menu-editor@example.test',
        'password' => 'unused-test-credential',
    ]);

    test()->actingAs(User::findOrFail(1));

    Gate::define('viewAny', static fn (User $user, mixed $record): bool => true);
    Gate::define('view', static fn (User $user, mixed $record): bool => true);
    Gate::define('useInMenu', static fn (User $user, mixed $record): bool => true);
    Gate::define('preview', static fn (User $user, mixed $record): bool => true);
    Gate::define('viewDraft', static fn (User $user, mixed $record): bool => true);
    Gate::define('update', static fn (User $user, mixed $record): bool => true);
    Gate::define('publish', static fn (User $user, mixed $record): bool => true);

    DB::table('pages')->insert([
        'id' => 1,
        'title' => 'Home',
        'slug' => 'home',
        'description' => 'Public Home page',
        'status' => PageStatus::PUBLISHED->value,
        'route' => '/home',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sections')->insert([
        'id' => 1,
        'title' => 'Specimens',
        'slug' => 'home-specimens',
        'description' => 'Home anchor target',
        'type' => 'specimens',
        'status' => SectionStatus::ACTIVE->value,
        'content' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('page_section')->insert([
        'page_id' => 1,
        'section_id' => 1,
        'order_column' => 1,
        'stage' => 'published',
        'occurrence_id' => fake()->uuid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens the native draft editor with saved rows instead of a blank default list', function (): void {
    prepareMenuContentTest();
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $panel = Panel::make()->id('menu-test')->path('menu-test')
        ->resources([MenuResource::class]);
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Route::get('/menu-test/menus', static fn (): string => 'Menus')->name('filament.menu-test.resources.menus.index');
    Route::get('/menu-test/menus/{record}/edit', static fn (): string => 'Edit')->name('filament.menu-test.resources.menus.edit');
    Route::getRoutes()->refreshNameLookups();
    $menu = app(MenuContent::class)->adoptConfiguredMenu('header');

    Livewire::test(EditMenu::class, ['record' => $menu->id])
        ->mountAction('saveMenuDraft')
        ->assertSet('mountedActions.0.data.items', fn (array $items): bool => array_column(array_values($items), 'label') === ['Specimens']);
});

it('keeps unaffected published links available after an unused valid declaration is added', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    config(['phi-cms.navigation.fixed_links.additional' => ['label' => 'Additional', 'route' => 'home']]);

    expect($content->published('header'))->toHaveCount(1);
    expect($content->published('header')[0])->toMatchArray(['label' => 'Specimens', 'href' => '/#specimens']);
});

it('explicitly reconciles additive declarations without publishing drafts and requires a fresh review', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $items = $content->draftItems('header');
    $items[0]['label'] = 'Private wildlife';
    $content->saveDraft('header', $items, 1);
    $review = $content->review('header', 2);
    $public = $content->published('header');
    config(['phi-cms.navigation.fixed_links.additional' => ['label' => 'Additional', 'route' => 'home']]);

    expect($content->reconcileConfiguredMenu('header', 2))->toBe(3);

    expect($content->published('header'))->toBe($public);
    expect($content->draftItems('header'))->toBe($items);
    expect(fn () => $content->publish('header', 2, $review['token']))->toThrow(MenuConflict::class);
    expect($content->review('header', 3)['publishable'])->toBeTrue();
    expect($menu->fresh()->adoption_bindings['fixed_links'])->toHaveKey('additional');
});

it('preflights declaration reconciliation without writing and applies it only explicitly', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $before = $menu->getAttributes();
    config(['phi-cms.navigation.fixed_links.additional' => ['label' => 'Additional', 'route' => 'home']]);

    $this->artisan('phi-cms:menus:adopt', ['key' => 'header', '--reconcile' => true])->assertSuccessful();
    expect($menu->fresh()->getAttributes())->toBe($before);

    $this->artisan('phi-cms:menus:adopt', ['key' => 'header', '--reconcile' => true, '--apply' => true])->assertSuccessful();
    expect($menu->fresh()->menu_version)->toBe(2);
    expect($content->reconcileConfiguredMenu('header', 2))->toBe(2);
});

it('refuses invalid or rebound declarations without changing saved state', function (array $configuration): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $before = $menu->getAttributes();
    $rows = $content->inventory('header');
    Route::get('/other', static fn (): string => 'Other')->name('other');
    Route::getRoutes()->refreshNameLookups();
    config($configuration);

    expect(fn () => $content->reconcileConfiguredMenu('header', 1))->toThrow(MenuConflict::class);

    expect($menu->fresh()->getAttributes())->toBe($before);
    expect($content->inventory('header'))->toBe($rows);
})->with([
    'invalid addition' => [['phi-cms.navigation.fixed_links.additional' => ['label' => 'Missing', 'route' => 'missing']]],
    'fixed route rebound' => [['phi-cms.navigation.fixed_links.home.route' => 'other']],
    'Page route rebound' => [['phi-cms.navigation.fixed_pages.home.route' => 'other']],
    'anchor removed' => [['phi-cms.navigation.fixed_pages.home.anchors' => []]],
]);

it('refuses reconciliation to a replacement Page with the same declared slug', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $before = $menu->getAttributes();
    $rows = $content->inventory('header');
    DB::table('pages')->where('id', 1)->update(['slug' => 'old-home']);
    $replacement = DB::table('pages')->where('id', 1)->first();
    $replacement->id = 2;
    $replacement->slug = 'home';
    DB::table('pages')->insert((array) $replacement);
    DB::table('page_section')->where('page_id', 1)->update(['page_id' => 2]);

    expect(fn () => $content->reconcileConfiguredMenu('header', 1))->toThrow(MenuConflict::class);

    expect($menu->fresh()->getAttributes())->toBe($before);
    expect($content->inventory('header'))->toBe($rows);
});

it('suppresses a fixed Page link when its declared host slug is reused by another Page', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    $original = DB::table('pages')->where('id', 1)->firstOrFail();

    DB::table('pages')->where('id', 1)->update(['slug' => 'old-home']);
    $replacement = (array) $original;
    $replacement['id'] = 2;
    $replacement['slug'] = 'home';
    DB::table('pages')->insert($replacement);

    expect($content->published('header'))->toBe([]);

    $review = $content->review('header', 1);

    expect($review['publishable'])->toBeFalse()
        ->and($review['items'][0])->toMatchArray([
            'href' => null,
            'status' => 'unavailable',
        ])
        ->and($review['feedback'][0]['message'])->toContain('persisted Page identity');
});

it('suppresses a fixed Page link when its adopted binding is missing', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $bindings = $menu->adoption_bindings;
    unset($bindings['fixed_pages']['home']);
    DB::table('menus')->where('id', $menu->getKey())->update([
        'adoption_bindings' => json_encode($bindings, JSON_THROW_ON_ERROR),
    ]);

    expect($content->published('header'))->toBe([]);

    $review = $content->review('header', 1);

    expect($review['publishable'])->toBeFalse()
        ->and($review['items'][0])->toMatchArray([
            'href' => null,
            'status' => 'unavailable',
        ])
        ->and($review['feedback'][0]['message'])->toContain('binding');
});

it('refuses stale reconciliation without changing saved state', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $before = $menu->getAttributes();
    $rows = $content->inventory('header');
    config(['phi-cms.navigation.fixed_links.additional' => ['label' => 'Additional', 'route' => 'home']]);

    expect(fn () => $content->reconcileConfiguredMenu('header', 0))->toThrow(MenuConflict::class);

    expect($menu->fresh()->getAttributes())->toBe($before);
    expect($content->inventory('header'))->toBe($rows);
});

it('suppresses an anchor whose declared slot changed while retaining unaffected public links', function (): void {
    prepareMenuContentTest();
    config(['phi-cms.navigation.fixed_pages.home.anchors.specimens.slot' => 'specimens']);
    DB::table('pages')->where('id', 1)->update(['page_version' => 1]);
    DB::table('page_section')->where('page_id', 1)->update(['slot' => 'specimens']);
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    $items = $content->draftItems('header');
    $items[] = ['item_key' => null, 'label' => 'Home root', 'destination' => ['kind' => 'fixed', 'key' => 'home']];
    $content->saveDraft('header', $items, 1);
    $review = $content->review('header', 2);
    $content->publish('header', 2, $review['token']);
    expect($content->published('header'))->toHaveCount(2);

    config(['phi-cms.navigation.fixed_pages.home.anchors.specimens.slot' => 'replacement']);

    expect($content->published('header'))->toHaveCount(1);
    expect($content->published('header')[0])->toMatchArray(['label' => 'Home root', 'href' => '/']);
});

it('suppresses only a changed fixed destination without retargeting it or breaking unaffected public links', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    $items = $content->draftItems('header');
    $items[] = ['item_key' => null, 'label' => 'Home root', 'destination' => ['kind' => 'fixed', 'key' => 'home']];
    $content->saveDraft('header', $items, 1);
    $review = $content->review('header', 2);
    $content->publish('header', 2, $review['token']);
    Route::get('/other', static fn (): string => 'Other')->name('other');
    Route::getRoutes()->refreshNameLookups();

    config(['phi-cms.navigation.fixed_links.home.route' => 'other']);

    expect($content->published('header'))->toHaveCount(1);
    expect($content->published('header')[0])->toMatchArray(['label' => 'Specimens', 'href' => '/#specimens']);
    expect(fn () => $content->review('header', 3))->toThrow(MenuConflict::class);
});

it('adopts the declared header as equal draft and public rows without changing legacy menus', function (): void {
    prepareMenuContentTest();
    $legacyMenuId = DB::table('menus')->insertGetId([
        'title' => 'Legacy nested menu',
        'description' => 'Keep unchanged',
        'slug' => 'legacy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('menu_items')->insert([
        'menu_id' => $legacyMenuId,
        'slug' => 'parent',
        'label' => 'Parent',
        'url' => '/parent',
        'items' => json_encode([['label' => 'Nested', 'url' => '/nested', 'unknown' => 'preserve']], JSON_THROW_ON_ERROR),
        'order_column' => 4,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyItemBefore = DB::table('menu_items')->where('menu_id', $legacyMenuId)->first();

    $menu = app(MenuContent::class)->adoptConfiguredMenu('header');
    $public = app(MenuContent::class)->published('header');
    $draftItems = MenuItem::query()->where('menu_id', $menu->id)->where('stage', 'draft')->get();
    $publishedItems = MenuItem::query()->where('menu_id', $menu->id)->where('stage', 'published')->get();

    expect($menu->menu_version)->toBe(1)
        ->and($menu->key)->toBe('header')
        ->and($draftItems)->toHaveCount(1)
        ->and($publishedItems)->toHaveCount(1)
        ->and($draftItems->first()->item_key)->toBe($publishedItems->first()->item_key)
        ->and($public)->toBe([
            ['id' => $publishedItems->first()->item_key, 'label' => 'Specimens', 'href' => '/#specimens'],
        ]);

    expect(DB::table('menu_items')->where('menu_id', $legacyMenuId)->first())->toEqual($legacyItemBefore);

    expect(app(MenuContent::class)->adoptConfiguredMenu('header')->getKey())->toBe($menu->getKey())
        ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(2);
});

it('keeps menu drafts private and preserves published row identities when it publishes reviewed changes', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $publishedItem = $menu->items()->firstOrFail();

    $version = $content->saveDraft('header', [[
        'item_key' => $publishedItem->item_key,
        'label' => 'Wildlife',
        'destination' => ['kind' => 'page', 'page_id' => 1, 'anchor' => 'specimens'],
    ]], 1);

    expect($version)->toBe(2)
        ->and($content->published('header'))
        ->toBe([['id' => $publishedItem->item_key, 'label' => 'Specimens', 'href' => '/#specimens']])
        ->and($content->preview('header')['items'][0]['label'])->toBe('Wildlife');

    $review = $content->review('header', 2);

    expect($review['publishable'])->toBeTrue()
        ->and($content->publish('header', 2, $review['token']))->toBe(3);

    expect($content->published('header'))
        ->toBe([['id' => $publishedItem->item_key, 'label' => 'Wildlife', 'href' => '/#specimens']])
        ->and($menu->items()->firstOrFail()->getKey())->toBe($publishedItem->getKey());
});

it('resolves current Page URLs and rejects a publication review after the public path changes', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();

    DB::table('pages')->insert([
        'id' => 2,
        'title' => 'Wildlife',
        'slug' => 'wildlife',
        'description' => 'Published wildlife guide',
        'status' => PageStatus::PUBLISHED->value,
        'route' => '/pages/wildlife',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('page_urls')->insert([
        'slug' => 'wildlife',
        'page_id' => 2,
        'kind' => 'current',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $content->saveDraft('header', [[
        'item_key' => $item->item_key,
        'label' => 'Wildlife',
        'destination' => ['kind' => 'page', 'page_id' => 2, 'anchor' => null],
    ]], 1);
    $review = $content->review('header', 2);
    DB::table('page_urls')->where('page_id', 2)->where('kind', 'current')->update([
        'slug' => 'wildlife-today',
        'updated_at' => now(),
    ]);

    expect($review['items'][0]['href'])->toBe('/pages/wildlife')
        ->and(fn () => $content->publish('header', 2, $review['token']))->toThrow(MenuConflict::class)
        ->and($content->published('header'))->toBe([
            ['id' => $item->item_key, 'label' => 'Specimens', 'href' => '/#specimens'],
        ]);

    $updatedReview = $content->review('header', 2);
    $content->publish('header', 2, $updatedReview['token']);

    expect($content->published('header'))->toBe([
        ['id' => $item->item_key, 'label' => 'Wildlife', 'href' => '/pages/wildlife-today'],
    ]);
});

it('denies a private draft preview when the manager lacks draft access', function (): void {
    prepareMenuContentTest();
    app(MenuContent::class)->adoptConfiguredMenu('header');
    Gate::define('viewDraft', static fn (User $user, mixed $record): bool => false);

    expect(fn () => app(MenuContent::class)->preview('header'))
        ->toThrow(AuthorizationException::class);

    expect(request()->attributes->get('phi-cms.private'))->toBeNull();
});

it('loads editable draft rows without granting preview authority', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    Gate::define('preview', static fn (User $user, mixed $record): bool => false);

    expect($content->draftItems('header'))->toBe([[
        'item_key' => DB::table('menu_items')->where('stage', 'draft')->value('item_key'),
        'label' => 'Specimens',
        'destination' => ['kind' => 'page', 'page_id' => 1, 'anchor' => 'specimens'],
    ]]);
});

it('marks reviewed draft content as private', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');

    $content->review('header', 1);

    expect(request()->attributes->get('phi-cms.private'))->toBeTrue();
});

it('rechecks update authorization while saving a menu draft', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();
    $authorizationChecks = 0;
    Gate::define('update', static function (User $user, mixed $record) use (&$authorizationChecks): bool {
        $authorizationChecks++;

        return $authorizationChecks === 1;
    });

    expect(fn () => $content->saveDraft('header', [[
        'item_key' => $item->item_key,
        'label' => 'Changed label',
        'destination' => ['kind' => 'page', 'page_id' => 1, 'anchor' => 'specimens'],
    ]], 1))->toThrow(AuthorizationException::class);

    expect($authorizationChecks)->toBe(2)
        ->and(DB::table('menus')->where('id', $menu->id)->value('menu_version'))->toBe(1)
        ->and(DB::table('menu_items')->where('id', $item->id)->value('label'))->toBe('Specimens');
});

it('rechecks publish authorization before applying a reviewed menu', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();
    $review = $content->review('header', 1);
    $authorizationChecks = 0;
    Gate::define('publish', static function (User $user, mixed $record) use (&$authorizationChecks): bool {
        $authorizationChecks++;

        return $authorizationChecks === 1;
    });

    expect(fn () => $content->publish('header', 1, $review['token']))
        ->toThrow(AuthorizationException::class);

    expect($authorizationChecks)->toBe(2)
        ->and(DB::table('menus')->where('id', $menu->id)->value('menu_version'))->toBe(1)
        ->and(DB::table('menu_items')->where('id', $item->id)->value('label'))->toBe('Specimens');
});

it('blocks publication while a selected Page remains draft-only', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $publishedItem = $menu->items()->firstOrFail();
    DB::table('pages')->where('id', 1)->update([
        'status' => PageStatus::DRAFT->value,
        'draft_fields' => json_encode(['title' => 'Unpublished Home', 'slug' => 'unpublished-home'], JSON_THROW_ON_ERROR),
    ]);
    $review = $content->review('header', 1);

    expect($review['publishable'])->toBeFalse()
        ->and($review['items'][0]['status'])->toBe('draft')
        ->and(fn () => $content->publish('header', 1, $review['token']))->toThrow(ValidationException::class)
        ->and(DB::table('menus')->where('id', $menu->id)->value('menu_version'))->toBe(1)
        ->and(DB::table('menu_items')->where('id', $publishedItem->id)->value('label'))->toBe('Specimens');
});

it('blocks publication when a declared anchor Section is no longer public', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $publishedItem = $menu->items()->firstOrFail();
    DB::table('sections')->where('id', 1)->update(['status' => SectionStatus::INACTIVE->value]);
    $review = $content->review('header', 1);

    expect($review['publishable'])->toBeFalse()
        ->and($review['feedback'])->not->toBeEmpty()
        ->and(fn () => $content->publish('header', 1, $review['token']))->toThrow(ValidationException::class)
        ->and(DB::table('menus')->where('id', $menu->id)->value('menu_version'))->toBe(1)
        ->and(DB::table('menu_items')->where('id', $publishedItem->id)->value('label'))->toBe('Specimens');
});

it('blocks publication when the adopted Page route is no longer registered', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $publishedItem = $menu->items()->firstOrFail();
    $routes = new RouteCollection;
    $routes->add(Route::getRoutes()->getByName('pages.show'));
    $routes->refreshNameLookups();
    Route::getFacadeRoot()->setRoutes($routes);
    $review = $content->review('header', 1);

    expect($review['publishable'])->toBeFalse()
        ->and($review['feedback'])->not->toBeEmpty()
        ->and(fn () => $content->publish('header', 1, $review['token']))->toThrow(ValidationException::class)
        ->and(DB::table('menus')->where('id', $menu->id)->value('menu_version'))->toBe(1)
        ->and(DB::table('menu_items')->where('id', $publishedItem->id)->value('label'))->toBe('Specimens');
});

it('prevents legacy model writes from claiming adopted menu ownership', function (): void {
    prepareMenuContentTest();
    $legacyMenuId = DB::table('menus')->insertGetId([
        'title' => 'Legacy menu',
        'description' => 'Keep separate',
        'slug' => 'legacy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyMenu = Menu::query()->findOrFail($legacyMenuId);
    $legacyMenu->key = 'header';
    $legacyMenu->menu_version = 1;
    $legacyMenu->adoption_bindings = [];

    expect(fn () => $legacyMenu->save())->toThrow(ValidationException::class);

    $menu = app(MenuContent::class)->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();
    $item->menu_id = $legacyMenuId;

    expect(fn () => $item->save())->toThrow(ValidationException::class)
        ->and(DB::table('menu_items')->where('id', $item->id)->value('menu_id'))->toBe($menu->id);
});

it('publishes explicitly untracked HTTP(S) and site-root destinations', function (string $url): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();

    $version = $content->saveDraft('header', [[
        'item_key' => $item->item_key,
        'label' => 'Visit',
        'destination' => ['kind' => 'custom', 'url' => $url],
    ]], 1);
    $review = $content->review('header', $version);

    expect($review['publishable'])->toBeTrue()
        ->and($review['items'][0]['href'])->toBe($url)
        ->and($review['items'][0]['status'])->toBe('untracked')
        ->and($content->publish('header', $version, $review['token']))->toBe(3)
        ->and($content->published('header')[0]['href'])->toBe($url);
})->with([
    'https URL' => 'https://example.test/species?region=west#birds',
    'http URL' => 'http://example.test/field-notes',
    'site-root path' => '/contact?source=menu#form',
]);

it('rejects unsafe custom destinations before changing the menu draft', function (string $url): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $item = $menu->draftItems()->firstOrFail();
    $before = $item->getRawOriginal();

    expect(fn () => $content->saveDraft('header', [[
        'item_key' => $item->item_key,
        'label' => 'Specimens',
        'destination' => ['kind' => 'custom', 'url' => $url],
    ]], 1))->toThrow(ValidationException::class);

    expect($item->fresh()->getRawOriginal())->toBe($before)
        ->and($content->published('header'))
        ->toBe([['id' => $item->item_key, 'label' => 'Specimens', 'href' => '/#specimens']]);
})->with([
    'executable scheme' => 'javascript:alert(1)',
    'unknown scheme' => 'mailto:editor@example.test',
    'protocol-relative URL' => '//unsafe.example.test/path',
    'fragment-only URL' => '#specimens',
    'credentials' => 'https://editor:secret@example.test/path',
    'encoded control' => '/path%0d%0aInjected: yes',
    'encoded protocol-relative path' => '/%2f%2funsafe.example.test/path',
    'backslash' => '/path\\unsafe',
]);

it('rechecks target authorities before private preview and publication', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $review = $content->review('header', $menu->menu_version);

    Gate::define('view', static fn (User $user, mixed $record): bool => false);

    expect(fn () => $content->preview('header'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $content->publish('header', $review['version'], $review['token']))
        ->toThrow(AuthorizationException::class)
        ->and($content->published('header'))
        ->toHaveCount(1);
});

it('preserves the underlying cause of a menu conflict', function (): void {
    $previous = new RuntimeException('deadlock detected');

    $conflict = new MenuConflict(previous: $previous);

    expect($conflict->getPrevious())->toBe($previous);
});

it('rejects an active adopted anchor without published content throughout the menu workflow', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    $review = $content->review('header', 1);

    DB::table('sections')->where('id', 1)->update(['content_version' => 1]);

    expect($content->review('header', 1)['publishable'])->toBeFalse()
        ->and($content->published('header'))->toBe([])
        ->and($content->anchorChoices('header', 1))->toBe([])
        ->and(fn () => $content->publish('header', 1, $review['token']))->toThrow(MenuConflict::class);
});

it('refuses preflight for a declared adopted anchor with no public content', function (): void {
    prepareMenuContentTest();
    DB::table('sections')->where('id', 1)->update(['content_version' => 1]);

    expect(fn () => app(MenuContent::class)->preflight('header'))->toThrow(MenuConflict::class);
});

it('labels draft Page choices as unpublished in searches and selected values', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    DB::table('pages')->where('id', 1)->update([
        'status' => PageStatus::DRAFT->value,
        'draft_fields' => json_encode(['title' => 'Unpublished Home', 'slug' => 'new-home'], JSON_THROW_ON_ERROR),
    ]);

    expect($content->pageChoice('header', 1)['label'])->toContain('Not published');
    foreach ($content->destinationChoices('header', 'Home') as $choice) {
        if (($choice['destination']['kind'] ?? null) === 'page') {
            expect($choice['label'])->toContain('Not published');
        }
    }
});

it('compares publication additions removals and label order and destination changes by item identity', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $menu = $content->adoptConfiguredMenu('header');
    $original = $content->draftItems('header')[0];
    $content->saveDraft('header', [$original, ['label' => 'Contact', 'destination' => ['kind' => 'custom', 'url' => '/contact']]], 1);
    $review = $content->review('header', 2);
    $content->publish('header', 2, $review['token']);
    $content->saveDraft('header', [
        ['label' => 'New link', 'destination' => ['kind' => 'custom', 'url' => '/new']],
        ['item_key' => $original['item_key'], 'label' => 'Wildlife', 'destination' => ['kind' => 'custom', 'url' => '/wildlife']],
    ], 3);

    $review = $content->review('header', 4);

    expect(array_column($review['changes'], 'type'))->toBe(['added', 'changed', 'removed'])
        ->and($review['changes'][1]['fields'])->toBe(['label', 'destination', 'order_column', 'href'])
        ->and($review['changes'][1]['before']['label'])->toBe('Specimens')
        ->and($review['changes'][1]['after']['label'])->toBe('Wildlife')
        ->and($review['changes'][2]['before']['label'])->toBe('Contact')
        ->and($review['changes'][2]['after'])->toBeNull();
});

it('identifies unchanged publication rows and keeps preview route configuration outside destination bindings', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    config(['phi-cms.navigation.menus.header.preview_route' => 'home']);

    $review = $content->review('header', 1);

    expect($review['changes'])->toHaveCount(1)
        ->and($review['changes'][0]['type'])->toBe('unchanged')
        ->and($review['changes'][0]['fields'])->toBe([])
        ->and($review['changes'][0]['before'])->toBe($review['changes'][0]['after']);
});

it('allows a fresh unavailable Page review but never reuses resolved values after the Page is removed', function (): void {
    prepareMenuContentTest();
    $content = app(MenuContent::class);
    $content->adoptConfiguredMenu('header');
    $oldReview = $content->review('header', 1);
    DB::table('pages')->where('id', 1)->delete();

    expect(fn () => $content->authorizeRetainedReview('header', $oldReview))
        ->toThrow(HttpException::class);

    $review = $content->review('header', 1);
    $content->authorizeRetainedReview('header', $review);

    expect($review['publishable'])->toBeFalse()
        ->and($review['items'][0]['target_label'])->toBeNull()
        ->and($review['items'][0]['message'])->toBe('The referenced Page has been removed.');
});
