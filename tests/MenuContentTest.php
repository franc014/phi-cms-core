<?php

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
