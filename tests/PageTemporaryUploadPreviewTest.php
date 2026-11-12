<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageSeoImageContext;
use JFA\PhiCmsCore\Pages\PageTemporaryUploads;
use JFA\PhiCmsCore\Sections\SectionTemporaryUploads;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

it('serves a Page temporary upload through the Livewire preview endpoint', function (): void {
    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'phi-cms.editing' => true,
        'livewire.temporary_file_upload.disk' => 'tmp-for-tests',
        'filesystems.disks.tmp-for-tests' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/tmp-for-tests'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
    ]);
    Storage::fake('tmp-for-tests');
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('status');
        $table->string('route');
        $table->dateTime('published_at')->nullable();
        $table->string('template')->nullable();
        $table->json('draft_fields')->nullable();
        $table->unsignedInteger('page_version')->nullable();
        $table->timestamps();
    });
    Schema::create('metatags', function (Blueprint $table): void {
        $table->id();
        $table->string('metatable_type');
        $table->unsignedBigInteger('metatable_id');
        $table->timestamps();
    });
    $page = Page::query()->create([
        'title' => 'Preview page',
        'slug' => 'preview-page',
        'description' => 'Preview fixture.',
        'status' => 'published',
        'route' => '/preview-page',
        'published_at' => now(),
        'page_version' => 1,
    ]);
    $user = User::forceCreate([
        'id' => 1,
        'name' => 'Previewer',
        'email' => 'previewer@example.test',
        'password' => 'unused',
    ]);
    Gate::define('preview', fn (User $actor, Page $authorizedPage): bool => $actor->is($user) && $authorizedPage->is($page));
    Gate::define('update', fn (User $actor, Page $authorizedPage): bool => $actor->is($user) && $authorizedPage->is($page));
    $this->actingAs($user);

    session()->start();
    $editor = Str::uuid()->toString();
    $token = app(PageTemporaryUploads::class)->activate($editor);
    session()->save();
    $this->withCookie(config('session.cookie'), session()->getId());
    $uploads = app(PageTemporaryUploads::class);
    app(PageSeoImageContext::class)->set($page, preview: true);
    $ticket = $uploads->issue($editor, $token, 'pageSeoImages.og_image');
    $filename = 'phi-' . $ticket . '-preview.jpg';
    $uploads->register($filename, $uploads->ticket($ticket));
    Storage::disk('tmp-for-tests')->put(FileUploadConfiguration::path($filename), 'page-preview');
    $url = URL::temporarySignedRoute(
        'livewire.preview-file',
        now()->addMinutes(5),
        ['filename' => $filename],
        absolute: false,
    );

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    expect($response->streamedContent())->toBe('page-preview');
});

it('serves a Section temporary upload through the Livewire preview endpoint', function (): void {
    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'phi-cms.editing' => true,
        'livewire.temporary_file_upload.disk' => 'tmp-for-tests',
        'filesystems.disks.tmp-for-tests' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/tmp-for-tests'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
    ]);
    Storage::fake('tmp-for-tests');
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
        $table->unsignedInteger('content_version')->nullable();
        $table->json('legacy_image_sources')->nullable();
        $table->timestamps();
    });
    $sectionId = DB::table('sections')->insertGetId([
        'title' => 'Preview section',
        'slug' => 'preview-section',
        'description' => 'Preview fixture.',
        'type' => 'gallery',
        'status' => 'active',
        'content' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $section = Section::query()->findOrFail($sectionId);
    $user = User::forceCreate([
        'id' => 1,
        'name' => 'Previewer',
        'email' => 'previewer@example.test',
        'password' => 'unused',
    ]);
    Gate::define('viewDraft', fn (User $actor, Section $authorizedSection): bool => $actor->is($user) && $authorizedSection->is($section));
    $this->actingAs($user);

    session()->start();
    $editor = Str::uuid()->toString();
    $uploads = app(SectionTemporaryUploads::class);
    $token = $uploads->activate($editor);
    session()->save();
    $this->withCookie(config('session.cookie'), session()->getId());
    $filename = 'phi-section-preview.jpg';
    $uploads->register($filename, [
        'actor' => (string) $user->getKey(),
        'session' => hash('sha256', session()->getId()),
        'editor' => $editor,
        'token' => $token,
        'section' => $section->getKey(),
        'page' => null,
        'field' => 'data.image',
    ]);
    Storage::disk('tmp-for-tests')->put(FileUploadConfiguration::path($filename), 'section-preview');
    $url = URL::temporarySignedRoute(
        'livewire.preview-file',
        now()->addMinutes(5),
        ['filename' => $filename],
        absolute: false,
    );

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('no-store');
    expect($response->streamedContent())->toBe('section-preview');
});
