<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

it('returns a JSON rejection instead of a redirect for a deceptive native upload', function (): void {
    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'phi-cms.editing' => true,
        'livewire.temporary_file_upload.disk' => 'tmp-for-tests',
    ]);
    Storage::fake('tmp-for-tests');
    config(['filesystems.disks.tmp-for-tests' => [
        'driver' => 'local', 'root' => Storage::disk('tmp-for-tests')->path(''),
        'serve' => false, 'visibility' => 'private', 'throw' => true,
    ]]);
    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->text('description');
        $table->string('slug');
        $table->string('type')->nullable();
        $table->json('content');
        $table->string('status');
        $table->unsignedBigInteger('content_version')->nullable();
        $table->json('draft_content')->nullable();
        $table->json('published_content')->nullable();
        $table->json('legacy_image_sources')->nullable();
        $table->timestamps();
    });
    DB::table('sections')->insert([
        'id' => 1, 'title' => 'Gallery', 'description' => '', 'slug' => 'gallery', 'type' => 'gallery',
        'content' => json_encode([]), 'status' => 'active', 'content_version' => 1,
        'draft_content' => json_encode([]), 'published_content' => json_encode([]),
        'legacy_image_sources' => json_encode([]),
    ]);
    $user = User::forceCreate(['id' => 1, 'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'unused']);
    $this->actingAs($user);
    Gate::define('viewDraft', fn (): bool => true);
    Gate::define('update', fn (): bool => true);

    session()->start();
    $editor = Str::uuid()->toString();
    session()->put('phi-cms.upload-editors.' . $editor, 'token');
    session()->save();
    $this->withCookie(config('session.cookie'), session()->getId());

    $ticket = Str::uuid()->toString();
    Cache::put('phi-cms.upload-ticket.' . $ticket, [
        'actor' => (string) $user->id, 'session' => hash('sha256', session()->getId()),
        'editor' => $editor, 'token' => 'token', 'section' => 1, 'page' => null,
        'field' => 'data.items.upload.image_url',
    ], now()->addMinutes(30));
    $url = URL::temporarySignedRoute('phi-cms.images.temporary', now()->addMinutes(5), ['ticket' => $ticket], absolute: false);

    $response = $this->post($url, ['files' => [UploadedFile::fake()->createWithContent('deceptive.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]]);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors' => ['files.0']]);
    $errors = $response->json('errors');
    expect($errors['files.0'])->not->toBeEmpty()
        ->and($response->json('message'))->toBeString()->not->toBe('');

    expect(Storage::disk('tmp-for-tests')->allFiles())->toBe([]);
});
