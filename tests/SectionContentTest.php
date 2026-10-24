<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Forms\Components\Image;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Sections\SectionContent;
use JFA\PhiCmsCore\Sections\SectionDefinition;
use JFA\PhiCmsCore\Sections\SectionRegistry;

class PlainSectionForCoreTest extends SectionDefinition
{
    public static function key(): string
    {
        return 'plain';
    }

    public static function view(): string
    {
        return 'core-test::plain';
    }

    public static function fields(): array
    {
        return [TextInput::make('title')->required()];
    }

    public static function normalize(array $content): array
    {
        return Validator::make(['content' => $content], ['content' => 'required|array:title', 'content.title' => 'required|string'])->validate()['content'];
    }
}

class ImagesForCoreTest extends PlainSectionForCoreTest
{
    public static function fields(): array
    {
        return [Image::make('cover'), Repeater::make('cards')->schema([Image::make('photo')->required()])];
    }
}

class PublicationImagesForCoreTest extends ImagesForCoreTest
{
    public static function normalize(array $content): array
    {
        return $content;
    }
}

/** @return array{Section, SectionContent, string} */
function reviewedLocalImageForCoreTest(): array
{
    View::addNamespace('core-test', __DIR__ . '/Fixtures/views');
    config(['phi-cms.sections' => [PublicationImagesForCoreTest::class], 'phi-cms.editing' => true]);
    Storage::fake('legacy');
    $root = Storage::disk('legacy')->path('');
    app()->usePublicPath($root);
    Storage::disk('legacy')->put('source.jpg', 'verified legacy bytes');
    $path = realpath($root . '/source.jpg');
    config(['filesystems.disks.legacy' => ['driver' => 'local', 'root' => $root]]);
    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('type');
        $table->json('published_content');
        $table->json('draft_content');
        $table->json('legacy_image_sources');
        $table->unsignedBigInteger('content_version');
        $table->timestamps();
    });
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->string('status');
        $table->timestamp('published_at')->nullable();
    });
    Schema::create('page_section', function (Blueprint $table): void {
        $table->unsignedBigInteger('page_id');
        $table->unsignedBigInteger('section_id');
        $table->integer('order_column');
    });
    $snapshot = ['title' => 'Public', 'cover' => null, 'cards' => [['photo' => 'source.jpg']]];
    $binding = ['kind' => 'disk', 'reference' => 'source.jpg', 'disk' => 'legacy', 'key' => 'source.jpg', 'root' => realpath($root), 'physical_path' => $path, 'url' => '/source.jpg', 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)];
    DB::table('sections')->insert(['id' => 1, 'type' => 'plain', 'content_version' => 1, 'published_content' => json_encode($snapshot), 'draft_content' => json_encode($snapshot), 'legacy_image_sources' => json_encode([$binding])]);
    DB::table('users')->insert(['id' => 1, 'name' => 'Publisher', 'email' => 'publisher@example.test', 'password' => 'unused-test-credential']);
    test()->actingAs(User::findOrFail(1));
    Gate::define('viewDraft', fn (User $user, Section $section): bool => true);
    Gate::define('update', fn (User $user, Section $section): bool => true);
    Gate::define('publish', fn (User $user, Section $section): bool => true);
    $section = Section::findOrFail(1);
    $content = app(SectionContent::class);
    $snapshot['title'] = 'Reviewed draft';
    $content->saveDraft($section, $snapshot, 1);
    $content->review($section, 2);

    return [$section->fresh(), $content, $path];
}

it('refuses publication when a reviewed adopted local original disappears without changing either snapshot or version', function (): void {
    [$section, $content, $path] = reviewedLocalImageForCoreTest();
    $before = $section->getRawOriginal();
    unlink($path);

    try {
        $content->publish($section, 2);
        test()->fail('Publication must refuse a missing adopted original.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['content.cards.0.photo' => ['The adopted image original is unavailable or has changed. Restore the verified source before publishing.']]);
    }

    expect($section->fresh()->getRawOriginal())->toBe($before);
});

it('publishes a reviewed adopted local original using its pinned disk rather than the default disk', function (): void {
    [$section, $content, $path] = reviewedLocalImageForCoreTest();
    config(['filesystems.default' => 'unrelated']);
    $hash = hash_file('sha256', $path);

    expect($content->publish($section, 2))->toBe(3);

    expect($section->fresh()->published_content)->toBe($section->draft_content);
    expect(hash_file('sha256', $path))->toBe($hash);
});

it('refuses publication when adopted original bytes change after review', function (): void {
    [$section, $content, $path] = reviewedLocalImageForCoreTest();
    $before = $section->getRawOriginal();
    file_put_contents($path, 'different bytes');

    expect(fn () => $content->publish($section, 2))->toThrow(ValidationException::class);

    expect($section->fresh()->getRawOriginal())->toBe($before);
});

it('publishes intentional external references without checking remote availability', function (): void {
    [$section, $content, $path] = reviewedLocalImageForCoreTest();
    $external = 'https://unavailable.example.test/exact.jpg?original=1';
    $snapshot = $section->draft_content;
    $snapshot['cards'][0]['photo'] = $external;
    DB::table('sections')->where('id', $section->id)->update(['draft_content' => json_encode($snapshot), 'legacy_image_sources' => json_encode([['kind' => 'external', 'reference' => $external, 'url' => $external]])]);
    unlink($path);
    $content->review($section, 2);

    expect($content->publish($section, 2))->toBe(3);

    expect($section->fresh()->published_content['cards'][0]['photo'])->toBe($external);
});

it('saves and publishes an intentional external URL selected without an adoption binding', function (): void {
    [$section, $content, $path] = reviewedLocalImageForCoreTest();
    $external = 'https://unavailable.example.test/native-selection.jpg?fit=cover&rev=2';
    $snapshot = $section->draft_content;
    $snapshot['cards'][0]['photo'] = $external;
    DB::table('sections')->where('id', $section->id)->update(['legacy_image_sources' => json_encode([])]);
    unlink($path);

    expect($content->saveDraft($section, $snapshot, 2))->toBe(3);
    $content->review($section, 3);

    expect($content->publish($section, 3))->toBe(4);
    expect($section->fresh()->published_content['cards'][0]['photo'])->toBe($external);
});

it('derives image references and requiredness from the native schema without a host reference map', function (): void {
    expect(ImagesForCoreTest::imageReferences(['cover' => null, 'cards' => [['photo' => 'media:first'], ['photo' => 'media:second']]]))->toBe([
        ['path' => 'content.cover', 'reference' => '', 'required' => false],
        ['path' => 'content.cards.0.photo', 'reference' => 'media:first', 'required' => true],
        ['path' => 'content.cards.1.photo', 'reference' => 'media:second', 'required' => true],
    ]);
});

it('rejects invalid non-null selections on optional native image fields', function (mixed $selection): void {
    expect(fn () => ImagesForCoreTest::imageReferences(['cover' => $selection, 'cards' => []]))->toThrow(ValidationException::class);
})->with(['array' => [[]], 'number' => [123], 'boolean' => [false], 'empty' => [''], 'whitespace' => ['   ']]);

it('renders and independently publishes plain keyed content with only Core providers and no editor activation on public reads', function (): void {
    View::addNamespace('core-test', __DIR__ . '/Fixtures/views');
    config(['phi-cms.sections' => [PlainSectionForCoreTest::class], 'phi-cms.editing' => false]);
    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('type');
        $table->json('published_content');
        $table->json('draft_content');
        $table->unsignedBigInteger('content_version');
        $table->timestamps();
    });
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->string('status');
        $table->timestamp('published_at')->nullable();
    });
    Schema::create('page_section', function (Blueprint $table): void {
        $table->unsignedBigInteger('page_id');
        $table->unsignedBigInteger('section_id');
        $table->integer('order_column');
    });
    DB::table('sections')->insert(['id' => 1, 'type' => 'plain', 'content_version' => 1, 'published_content' => json_encode(['title' => 'Public <text>']), 'draft_content' => json_encode(['title' => 'Private sentinel'])]);
    DB::table('users')->insert(['id' => 1, 'name' => 'Actor', 'email' => 'actor@example.test', 'password' => 'unused-test-credential']);
    $section = Section::findOrFail(1);
    $content = app(SectionContent::class);

    $html = Blade::render('<x-phi-cms-core::section :section="$section" />', compact('section'));
    expect($html)->toContain('Public &lt;text&gt;')->not->toContain('Private sentinel', 'selectSection');
    config(['phi-cms.editing' => true]);
    $this->actingAs(User::findOrFail(1));
    Gate::define('viewDraft', fn (User $user, Section $section): bool => true);
    Gate::define('update', fn (User $user, Section $section): bool => true);
    Gate::define('publish', fn (User $user, Section $section): bool => true);
    $version = $content->saveDraft($section, ['title' => 'Saved private text'], 1);
    expect($content->published($section))->toBe(['title' => 'Public <text>']);
    expect($content->draft($section))->toBe(['title' => 'Saved private text']);
    $content->review($section, $version);
    $content->publish($section, $version);
    expect($content->published($section))->toBe(['title' => 'Saved private text']);
});

it('rejects duplicate registrations and unregistered types rather than silently picking a definition', function (): void {
    View::addNamespace('core-test', __DIR__ . '/Fixtures/views');
    expect(fn () => new SectionRegistry([PlainSectionForCoreTest::class, PlainSectionForCoreTest::class]))->toThrow(InvalidArgumentException::class, 'duplicate');
    expect(fn () => (new SectionRegistry([PlainSectionForCoreTest::class]))->get('missing'))->toThrow(InvalidArgumentException::class, 'Missing Section definition');
    expect(fn () => new SectionRegistry([stdClass::class]))->toThrow(InvalidArgumentException::class, 'Invalid Section definition');
});
