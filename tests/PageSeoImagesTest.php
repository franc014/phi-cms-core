<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Pages\PageSeoImageContext;
use JFA\PhiCmsCore\Pages\PageSeoImages;
use JFA\PhiCmsCore\Pages\PageTemporaryUploads;
use JFA\PhiCmsCore\Sections\SectionDefinition;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

class SeoInventorySectionForCoreTest extends SectionDefinition
{
    public static function key(): string
    {
        return 'seo-inventory';
    }

    public static function view(): string
    {
        return 'core-test::plain';
    }

    public static function fields(): array
    {
        return [];
    }

    public static function normalize(array $content): array
    {
        if (array_keys($content) !== ['title'] || ! is_string($content['title'])) {
            throw new RuntimeException('Invalid present snapshot.');
        }

        return $content;
    }
}

it('allows absent public snapshots but refuses unknown or malformed current inventories during cleanup', function (string $snapshot): void {
    ['media' => $media] = coreSeoCleanupFixture();
    View::addNamespace('core-test', __DIR__ . '/Fixtures/views');
    config(['phi-cms.sections' => [SeoInventorySectionForCoreTest::class]]);
    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('type');
        $table->unsignedInteger('content_version')->nullable();
        $table->json('content')->nullable();
        $table->json('draft_content')->nullable();
        $table->json('published_content')->nullable();
        $table->json('legacy_image_sources')->nullable();
    });
    DB::table('sections')->insert([
        'type' => $snapshot === 'unknown' ? 'unregistered' : 'seo-inventory',
        'content_version' => 1,
        'draft_content' => json_encode(['title' => 'Valid draft']),
        'published_content' => $snapshot === 'malformed' ? '[]' : null,
    ]);
    DB::commit();

    $this->artisan('phi-cms:delete-image', ['uuid' => $media->uuid, '--dry-run' => true])
        ->expectsOutputToContain($snapshot === 'absent' ? '"usages":[]' : 'incomplete-inventory')
        ->assertSuccessful();
    Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
    $command = $this->artisan('phi-cms:delete-image', ['uuid' => $media->uuid, '--force' => true]);
    if ($snapshot === 'absent') {
        $command->assertSuccessful()->run();
        $this->assertModelMissing($media);
        Storage::disk('phi-cms-images')->assertMissing($media->getPathRelativeToRoot());
    } else {
        $command->assertFailed()->run();
        $this->assertModelExists($media);
        Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
        expect($media->fresh()->getCustomProperty('phi_tombstoned_at'))->toBeNull();
    }
})->with(['absent', 'malformed', 'unknown']);

/** @return array{page: Page, media: Media, reference: string} */
function coreSeoCleanupFixture(): array
{
    app()->register(MediaLibraryServiceProvider::class);
    config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32)), 'phi-cms.editing' => true]);
    Storage::fake('phi-cms-images');
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('route');
        $table->string('status');
        $table->unsignedInteger('page_version')->nullable();
        $table->json('draft_fields')->nullable();
        $table->timestamps();
    });
    Schema::create('metatags', function (Blueprint $table): void {
        $table->id();
        $table->string('metatable_type');
        $table->unsignedBigInteger('metatable_id');
        $table->string('og_image')->nullable();
        $table->string('twitter_image')->nullable();
        $table->timestamps();
    });
    Schema::create('media', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->string('collection_name');
        $table->string('name');
        $table->string('file_name');
        $table->string('mime_type');
        $table->string('disk');
        $table->unsignedBigInteger('size');
        $table->json('custom_properties');
        $table->timestamps();
    });
    $page = Page::query()->create([
        'title' => 'SEO owner', 'slug' => 'seo-owner', 'description' => 'Fixture.',
        'route' => '/seo-owner', 'status' => 'draft', 'page_version' => 1,
        'draft_fields' => ['metatags' => ['og_image' => null, 'twitter_image' => null]],
    ]);
    $uuid = Str::uuid()->toString();
    DB::table('media')->insert([
        'uuid' => $uuid, 'model_type' => $page->getMorphClass(), 'model_id' => $page->id,
        'collection_name' => 'phi-page-seo-images', 'name' => 'Original',
        'file_name' => 'original.jpg', 'mime_type' => 'image/jpeg',
        'disk' => 'phi-cms-images', 'size' => 8, 'custom_properties' => '{}',
    ]);
    $media = Media::query()->where('uuid', $uuid)->firstOrFail();
    Storage::disk('phi-cms-images')->put($media->getPathRelativeToRoot(), 'original');
    $user = User::forceCreate(['id' => 1, 'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'unused']);
    test()->actingAs($user);
    Gate::define('preview', fn (User $actor, Page $owner): bool => $actor->is($user) && $owner->is($page));
    Gate::define('update', fn (User $actor, Page $owner): bool => $actor->is($user) && $owner->is($page));

    return ['page' => $page, 'media' => $media, 'reference' => 'media:' . $uuid];
}

/** @return array{page: Page, media: Media, reference: string} */
function coreSeoReleaseFixture(): array
{
    $fixture = coreSeoCleanupFixture();
    app()->register(ActivitylogServiceProvider::class);
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->nullableMorphs('subject');
        $table->nullableMorphs('causer');
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->json('properties')->nullable();
        $table->timestamps();
    });
    DB::table('metatags')->insert([
        'metatable_type' => $fixture['page']->getMorphClass(),
        'metatable_id' => $fixture['page']->id,
        'og_image' => $fixture['reference'],
        'twitter_image' => $fixture['reference'],
    ]);
    DB::commit();

    return $fixture;
}

it('rejects stale noninteractive owner release confirmation without mutation even with force', function (): void {
    ['page' => $page, 'media' => $media, 'reference' => $reference] = coreSeoReleaseFixture();
    $plan = app(PageSeoImages::class)->inspectUnpublishedOwnerReference($media);
    $this->artisan('phi-cms:delete-image', [
        'uuid' => $media->uuid, '--dry-run' => true, '--clear-unpublished-owner-reference' => true,
    ])->expectsOutputToContain($plan['confirmation'])->assertSuccessful();
    DB::table('pages')->where('id', $page->id)->update(['page_version' => 2]);

    $this->artisan('phi-cms:delete-image', [
        'uuid' => $media->uuid, '--force' => true, '--clear-unpublished-owner-reference' => true,
        '--owner-release-plan' => $plan['confirmation'],
    ])->expectsOutputToContain('release plan has changed')->assertFailed();

    $this->assertDatabaseHas('metatags', ['og_image' => $reference, 'twitter_image' => $reference]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 2]);
    $this->assertDatabaseCount('activity_log', 0);
    expect($media->fresh()->getCustomProperty('phi_tombstoned_at'))->toBeNull();
    Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
});

it('audits an exactly confirmed release before separately deleting unused media', function (): void {
    ['page' => $page, 'media' => $media, 'reference' => $reference] = coreSeoReleaseFixture();
    $plan = app(PageSeoImages::class)->inspectUnpublishedOwnerReference($media);

    $this->artisan('phi-cms:delete-image', [
        'uuid' => $media->uuid, '--force' => true, '--clear-unpublished-owner-reference' => true,
        '--owner-release-plan' => $plan['confirmation'],
    ])->assertSuccessful();

    $this->assertDatabaseHas('metatags', ['og_image' => null, 'twitter_image' => null]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 2, 'status' => 'draft']);
    $this->assertModelMissing($media);
    Storage::disk('phi-cms-images')->assertMissing($media->getPathRelativeToRoot());
    $audit = Activity::query()->sole();
    expect($audit->properties['owner_release'])->toBe($plan);
    expect($audit->properties['owner_release']['old_values'])->toBe([1 => ['og_image' => $reference, 'twitter_image' => $reference]]);
});

it('does not release references when durable audit storage is unavailable', function (): void {
    ['page' => $page, 'media' => $media, 'reference' => $reference] = coreSeoReleaseFixture();
    $plan = app(PageSeoImages::class)->inspectUnpublishedOwnerReference($media);
    config(['activitylog.table_name' => 'unavailable_audit_table']);

    $this->artisan('phi-cms:delete-image', [
        'uuid' => $media->uuid, '--force' => true, '--clear-unpublished-owner-reference' => true,
        '--owner-release-plan' => $plan['confirmation'],
    ])->assertFailed();

    $this->assertDatabaseHas('metatags', ['og_image' => $reference, 'twitter_image' => $reference]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
    $this->assertDatabaseCount('activity_log', 0);
    expect($media->fresh()->getCustomProperty('phi_tombstoned_at'))->toBeNull();
    Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
});

it('refuses unreviewed forced release and permits cancelling interactive release without mutation', function (string $mode): void {
    ['page' => $page, 'media' => $media, 'reference' => $reference] = coreSeoReleaseFixture();
    $command = $this->artisan('phi-cms:delete-image', [
        'uuid' => $media->uuid, '--clear-unpublished-owner-reference' => true,
        $mode === 'force' ? '--force' : '--confirm' => true,
    ]);
    if ($mode === 'cancel') {
        $command->expectsConfirmation('Release these retained Page references, increment its version, and then attempt physical deletion?', 'no');
    }
    $command->assertFailed()->run();

    $this->assertDatabaseHas('metatags', ['og_image' => $reference, 'twitter_image' => $reference]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
    $this->assertDatabaseCount('activity_log', 0);
    expect($media->fresh()->getCustomProperty('phi_tombstoned_at'))->toBeNull();
    Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
})->with(['force', 'cancel']);

it('serves an unsaved candidate only to its active editor and keeps saved references reusable', function (): void {
    ['page' => $page, 'media' => $media, 'reference' => $reference] = coreSeoCleanupFixture();
    session()->start();
    $context = app(PageSeoImageContext::class);
    $context->set($page);
    $token = app(PageTemporaryUploads::class)->activate('origin');
    $context->set($page, editor: 'origin', token: $token);
    DB::table('media')->where('id', $media->id)->update(['custom_properties' => json_encode([
        'phi_candidate' => [
            'actor' => (string) auth()->id(), 'session' => hash('sha256', session()->getId()),
            'page' => $page->id, 'editor' => 'origin', 'token' => $token,
        ],
    ], JSON_THROW_ON_ERROR)]);
    $url = app(PageSeoImages::class)->metadata($reference)['url'];
    session()->save();
    $this->withCookie(config('session.cookie'), session()->getId());

    $this->get($url)->assertOk();
    $context->set($page);
    app(PageTemporaryUploads::class)->activate('other');
    session()->save();
    $this->get($url)->assertNotFound();
    $this->get(route('phi-cms.page-images.preview', ['page' => $page, 'uuid' => $media->uuid]))->assertNotFound();

    DB::table('pages')->where('id', $page->id)->update(['draft_fields' => json_encode([
        'metatags' => ['twitter_image' => $reference],
    ], JSON_THROW_ON_ERROR)]);
    $this->get(route('phi-cms.page-images.preview', ['page' => $page, 'uuid' => $media->uuid]))->assertOk();
    Storage::disk('phi-cms-images')->assertExists($media->getPathRelativeToRoot());
});

it('accepts decoded supported originals and rejects unsupported uploads', function (): void {
    $images = app(PageSeoImages::class);
    $valid = UploadedFile::fake()->image('seo.jpg', 40, 40);

    $images->validateFile($valid->getRealPath());

    $invalid = UploadedFile::fake()->create('seo.txt', 1, 'text/plain');

    expect(fn () => $images->validateFile($invalid->getRealPath()))
        ->toThrow(ValidationException::class, 'Only decoded JPEG, PNG, and WebP images are accepted.');
});

it('rejects moving Page SEO media into or out of its protected identity', function (array $original, array $changes): void {
    $media = new Media;
    $media->setRawAttributes($original, true);
    $media->forceFill($changes);

    expect(fn () => $media->save())->toThrow(ValidationException::class, 'Page SEO images are immutable');
})->with([
    'collection escape' => [
        ['model_type' => Page::class, 'model_id' => 1, 'collection_name' => 'phi-page-seo-images'],
        ['collection_name' => 'other'],
    ],
    'owner and collection escape' => [
        ['model_type' => Page::class, 'model_id' => 1, 'collection_name' => 'phi-page-seo-images'],
        ['model_type' => 'other', 'model_id' => 2, 'collection_name' => 'other'],
    ],
    'move into protection' => [
        ['model_type' => 'other', 'model_id' => 2, 'collection_name' => 'other'],
        ['model_type' => Page::class, 'model_id' => 1, 'collection_name' => 'phi-page-seo-images'],
    ],
]);

it('protects managed Page metatags through model and relationship writers', function (string $operation): void {
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->unsignedInteger('page_version')->nullable();
        $table->json('draft_fields')->nullable();
    });
    Schema::create('metatags', function (Blueprint $table): void {
        $table->id();
        $table->string('metatable_type');
        $table->unsignedBigInteger('metatable_id');
        $table->string('title')->nullable();
        $table->string('og_image')->nullable();
        $table->timestamps();
    });
    DB::table('pages')->insert(['id' => 1, 'page_version' => 1]);
    DB::table('metatags')->insert([
        'id' => 1,
        'metatable_type' => (new Page)->getMorphClass(),
        'metatable_id' => 1,
        'title' => 'Committed',
    ]);
    $page = Page::query()->findOrFail(1);

    expect(fn () => match ($operation) {
        'update' => $page->metatags->update(['title' => 'Bypass']),
        'delete' => $page->metatags->delete(),
        'create' => $page->metatags()->create(['title' => 'Bypass']),
        'relationship-update' => $page->metatags()->update(['title' => 'Bypass']),
        'relationship-delete' => $page->metatags()->delete(),
        'reassign' => $page->metatags->update(['metatable_id' => 2]),
    })->toThrow(ValidationException::class, 'Page draft workflow');

    $this->assertDatabaseHas('metatags', ['id' => 1, 'metatable_id' => 1, 'title' => 'Committed']);
    $this->assertDatabaseCount('metatags', 1);
})->with(['update', 'delete', 'create', 'relationship-update', 'relationship-delete', 'reassign']);
