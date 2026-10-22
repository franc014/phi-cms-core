<?php

use Filament\Forms\Components\TextInput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
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
