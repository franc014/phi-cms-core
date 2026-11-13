<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Models\Media;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Pages\PageSeoImages;

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
