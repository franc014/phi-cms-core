<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\Section;
use JFA\PhiCmsCore\Pages\PageTemplate;
use JFA\PhiCmsCore\Sections\SectionContent;
use JFA\PhiCmsCore\Sections\SectionDefinition;

class PreviewSectionDefinitionForCoreTest extends SectionDefinition
{
    public static function key(): string
    {
        return 'preview-plain';
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
        return $content;
    }
}

class PreviewPageTemplateForCoreTest extends PageTemplate
{
    public static function key(): string
    {
        return 'preview-page';
    }

    public static function view(): string
    {
        return 'core-test::plain';
    }

    /** @return array{mode: 'ordered', allowed: list<string>, required: list<string>} */
    public static function composition(): array
    {
        return ['mode' => 'ordered', 'allowed' => ['preview-plain'], 'required' => []];
    }
}

it('labels published Section fallbacks and preserves an unavailable marker in Page preview', function (): void {
    View::addNamespace('core-test', __DIR__ . '/Fixtures/views');
    config([
        'phi-cms.editing' => true,
        'phi-cms.sections' => [PreviewSectionDefinitionForCoreTest::class],
        'phi-cms.pages' => [PreviewPageTemplateForCoreTest::class],
    ]);

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
    Schema::create('page_section', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('page_id');
        $table->foreignId('section_id');
        $table->unsignedInteger('order_column');
        $table->string('stage');
        $table->uuid('occurrence_id')->nullable();
        $table->unsignedInteger('slot')->nullable();
        $table->timestamps();
    });
    Schema::create('metatags', function (Blueprint $table): void {
        $table->id();
        $table->string('metatable_type');
        $table->unsignedBigInteger('metatable_id');
        $table->string('title')->nullable();
        $table->text('description')->nullable();
        $table->string('og_title')->nullable();
        $table->text('og_description')->nullable();
        $table->string('og_image')->nullable();
        $table->string('twitter_title')->nullable();
        $table->string('twitter_card')->nullable();
        $table->text('twitter_description')->nullable();
        $table->string('twitter_image')->nullable();
        $table->string('robots')->nullable();
        $table->timestamps();
    });

    DB::table('users')->insert(['id' => 1, 'name' => 'Previewer', 'email' => 'previewer@example.test', 'password' => 'unused']);
    $actor = User::findOrFail(1);
    test()->actingAs($actor);
    Gate::define('preview', fn (User $user, Page $page): bool => true);
    Gate::define('viewDraft', fn (User $user, Section $section): bool => false);

    $published = ['title' => 'Published fallback'];
    DB::table('sections')->insert([
        'title' => 'Services',
        'slug' => 'services',
        'description' => 'Preview fixture',
        'type' => 'preview-plain',
        'status' => SectionStatus::ACTIVE,
        'content' => json_encode($published, JSON_THROW_ON_ERROR),
        'draft_content' => json_encode(['title' => 'Draft-only secret'], JSON_THROW_ON_ERROR),
        'published_content' => json_encode($published, JSON_THROW_ON_ERROR),
        'content_version' => 2,
    ]);
    $section = Section::query()->findOrFail(1);
    $page = Page::query()->create([
        'title' => 'Company',
        'slug' => 'company',
        'description' => 'Preview fixture',
        'route' => '/company',
        'status' => PageStatus::PUBLISHED,
        'published_at' => now(),
        'template' => 'preview-page',
        'draft_fields' => [
            'title' => 'Company',
            'slug' => 'company',
            'description' => 'Preview fixture',
            'template' => 'preview-page',
            'metatags' => null,
        ],
        'page_version' => 1,
    ]);
    DB::table('page_section')->insert([
        'page_id' => $page->id,
        'section_id' => $section->id,
        'order_column' => 1,
        'stage' => 'draft',
        'occurrence_id' => fake()->uuid(),
    ]);

    $rendered = app(SectionContent::class)->renderPage($page, preview: true);

    expect($rendered[$section->id]['source'])->toBe('published')
        ->and($rendered[$section->id]['unavailable'])->toBeFalse()
        ->and($rendered[$section->id]['content'])->toBe($published);

    DB::table('sections')->where('id', $section->id)->update(['published_content' => null]);
    $rendered = app(SectionContent::class)->renderPage($page, preview: true);

    expect($rendered[$section->id]['source'])->toBe('unavailable')
        ->and($rendered[$section->id]['unavailable'])->toBeTrue();
});
