<?php

use Filament\Panel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Livewire\Preview;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Pages\PageContent;
use JFA\PhiCmsCore\Pages\PageTemplate;
use JFA\PhiCmsCore\Sections\SectionDefinition;
use Livewire\Livewire;

class FixedWorkflowSection extends SectionDefinition
{
    public static function key(): string
    {
        return 'fixed-workflow';
    }

    public static function view(): string
    {
        return 'fixed-workflow::section';
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

class FixedWorkflowPage extends PageTemplate
{
    public static function key(): string
    {
        return 'fixed-workflow';
    }

    public static function view(): string
    {
        return 'fixed-workflow::page';
    }

    public static function fixedSlug(): ?string
    {
        return 'home';
    }

    public static function composition(): array
    {
        return [
            'mode' => 'fixed',
            'allowed' => ['fixed-workflow'],
            'required' => ['fixed-workflow'],
            'slots' => ['hero' => ['type' => 'fixed-workflow', 'required' => true, 'selectable' => false]],
        ];
    }
}

class VariableWorkflowPage extends PageTemplate
{
    public static function key(): string
    {
        return 'fixed-workflow';
    }

    public static function view(): string
    {
        return FixedWorkflowPage::view();
    }

    public static function composition(): array
    {
        return FixedWorkflowPage::composition();
    }
}

function fixedWorkflowFixture(): Page
{
    $panel = Panel::make()->id('fixed-workflow')->default();
    Filament\Facades\Filament::registerPanel($panel);
    Filament\Facades\Filament::setCurrentPanel($panel);
    Route::get('/fixed-workflow/pages', static fn (): string => 'Pages')
        ->name('filament.fixed-workflow.resources.pages.index');
    Route::get('/fixed-workflow/pages/{record}/sections', static fn (): string => 'Sections')
        ->name('filament.fixed-workflow.resources.pages.manageSections');
    Route::getRoutes()->refreshNameLookups();
    View::addNamespace('fixed-workflow', __DIR__ . '/Fixtures/fixed-workflow');
    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'phi-cms.editing' => true,
        'phi-cms.sections' => [FixedWorkflowSection::class],
        'phi-cms.pages' => [FixedWorkflowPage::class],
        'phi-cms.page_publication_view' => 'fixed-workflow::publication',
    ]);
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('route');
        $table->string('status');
        $table->timestamp('published_at')->nullable();
        $table->string('template');
        $table->json('draft_fields');
        $table->unsignedInteger('page_version');
        $table->timestamps();
    });
    Schema::create('sections', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->text('description');
        $table->string('type');
        $table->string('status');
        $table->json('content');
        $table->json('draft_content');
        $table->json('published_content');
        $table->unsignedInteger('content_version');
        $table->json('legacy_image_sources')->nullable();
        $table->timestamps();
    });
    Schema::create('page_section', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('page_id');
        $table->unsignedBigInteger('section_id');
        $table->string('stage');
        $table->uuid('occurrence_id');
        $table->string('slot')->nullable();
        $table->unsignedInteger('order_column');
        $table->timestamps();
    });
    Schema::create('page_urls', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('page_id');
        $table->string('slug')->unique();
        $table->string('kind');
        $table->timestamps();
    });
    Schema::create('page_legacy_occurrences', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('page_id');
        $table->unsignedBigInteger('section_id');
        $table->uuid('occurrence_id');
    });
    Schema::create('metatags', function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('metatable');
        $table->timestamps();
    });
    Schema::create('media', function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('model');
        $table->uuid('uuid')->nullable();
        $table->string('collection_name');
        $table->json('custom_properties');
        $table->timestamps();
    });
    $actor = User::forceCreate(['name' => 'Editor', 'email' => 'fixed@example.test', 'password' => 'unused']);
    test()->actingAs($actor);
    foreach (['preview', 'viewDraft', 'update', 'compose', 'publish', 'view', 'viewAny', 'useInPage', 'create'] as $ability) {
        Gate::define($ability, fn (User $user): bool => $user->is($actor));
    }
    $fields = ['title' => 'Home', 'slug' => 'home', 'description' => 'Fixture', 'template' => 'fixed-workflow', 'metatags' => null];
    $pageId = DB::table('pages')->insertGetId([
        ...collect($fields)->except('metatags')->all(),
        'route' => '/', 'status' => 'published', 'page_version' => 1,
        'draft_fields' => json_encode($fields),
    ]);
    $sectionId = DB::table('sections')->insertGetId([
        'title' => 'Hero', 'slug' => 'hero', 'description' => 'Fixture', 'type' => 'fixed-workflow', 'status' => 'active',
        'content' => '{}', 'draft_content' => '{"title":"Private draft"}', 'published_content' => '{"title":"Published Hero"}', 'content_version' => 1,
    ]);
    foreach (['draft', 'published'] as $stage) {
        DB::table('page_section')->insert([
            'page_id' => $pageId, 'section_id' => $sectionId, 'stage' => $stage,
            'slot' => 'hero', 'order_column' => 1, 'occurrence_id' => 'f5b6af1c-e217-40e5-b658-5322336e772c',
        ]);
    }

    return Page::findOrFail($pageId);
}

it('rejects a forged fixed lookup slug through native Page draft save without mutation', function (): void {
    $page = fixedWorkflowFixture();
    $before = $page->getAttributes();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->set('pageData.slug', 'renamed-home')
        ->call('savePageDraft')
        ->assertHasErrors('fields.slug');

    $this->assertDatabaseHas('pages', $before);
});

it('rejects a saved invalid fixed lookup slug at publication review', function (): void {
    $page = fixedWorkflowFixture();
    $fields = $page->draft_fields;
    $fields['slug'] = 'renamed-home';
    DB::table('pages')->where('id', $page->id)->update(['draft_fields' => json_encode($fields)]);
    $before = $page->fresh()->getAttributes();

    expect(fn () => app(PageContent::class)->review($page, 1))
        ->toThrow(ValidationException::class, 'The slug is developer-controlled for this Page template.');

    $this->assertDatabaseHas('pages', $before);
});

it('rechecks the fixed lookup slug when a reviewed draft is forged before publication', function (): void {
    $page = fixedWorkflowFixture();
    $workflow = app(PageContent::class);
    $review = $workflow->review($page, 1);
    $fields = $page->draft_fields;
    $fields['slug'] = 'renamed-home';
    DB::table('pages')->where('id', $page->id)->update(['draft_fields' => json_encode($fields)]);
    $before = $page->fresh()->getAttributes();

    expect(fn () => $workflow->publish($page, 1, $review['token']))
        ->toThrow(ValidationException::class, 'The slug is developer-controlled for this Page template.');

    $this->assertDatabaseHas('pages', $before);
    $this->assertDatabaseCount('page_urls', 0);
});

it('retains ordinary editable slugs for templates without a fixed lookup', function (): void {
    $page = fixedWorkflowFixture();
    config(['phi-cms.pages' => [VariableWorkflowPage::class]]);
    $fields = $page->draft_fields;
    $fields['slug'] = 'renamed-page';

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->set('pageData.slug', 'renamed-page')
        ->call('savePageDraft')->assertHasNoErrors()
        ->call('reviewPagePublication')->assertHasNoErrors()
        ->call('publishPage')->assertHasNoErrors();

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'renamed-page', 'draft_fields->slug' => 'renamed-page']);
});

it('refuses native replacement in a nonselectable named slot without changing either stage', function (): void {
    $page = fixedWorkflowFixture();
    $original = DB::table('sections')->first();
    $replacement = (array) $original;
    unset($replacement['id']);
    $replacement['slug'] = 'replacement-hero';
    $replacementId = DB::table('sections')->insertGetId($replacement);
    $assignments = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->set('pageRows.0.section_id', $replacementId)
        ->call('savePageComposition')->assertHasErrors('workflow')
        ->assertSee('An existing occurrence cannot be repointed.');

    foreach ($assignments as $assignment) {
        $this->assertDatabaseHas('page_section', $assignment);
    }
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
});

it('refuses forged native fixed occurrence state without changing assignments', function (string $field, string $value): void {
    $page = fixedWorkflowFixture();
    $assignments = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->set('pageRows.0.' . $field, $value)
        ->call('savePageComposition')->assertHasErrors();

    foreach ($assignments as $assignment) {
        $this->assertDatabaseHas('page_section', $assignment);
    }
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
})->with([
    'moving slot identity' => ['slot', 'other'],
    'client occurrence identity' => ['occurrence_id', 'forged'],
]);

it('rejects a different fixed lookup slug on direct Page creation', function (): void {
    fixedWorkflowFixture();

    expect(fn () => app(PageContent::class)->createDraft([
        'title' => 'Forged', 'slug' => 'renamed-home', 'description' => 'Fixture', 'template' => 'fixed-workflow',
    ]))->toThrow(ValidationException::class, 'The slug is developer-controlled for this Page template.');

    $this->assertDatabaseCount('pages', 1);
});

it('preserves named slots in native composition saves and allows incomplete drafts only', function (): void {
    $page = fixedWorkflowFixture();
    $pivotId = (int) $page->draftSectionAssignments()->firstOrFail()->id;
    $component = Livewire::test(Preview::class, ['pageId' => $page->id])
        ->assertSet('pageRows.0.slot', 'hero')
        ->call('savePageComposition')->assertHasNoErrors()
        ->assertSet('version', 2);

    $component->call('removePageSection', $pivotId)->assertHasNoErrors()
        ->assertSet('pageRows', [])->assertSet('version', 3)
        ->call('savePageDraft')->assertHasNoErrors()
        ->call('reviewPagePublication')->assertHasErrors('sections');

    $this->assertDatabaseHas('page_section', ['page_id' => $page->id, 'stage' => 'published', 'slot' => 'hero']);
    $this->assertDatabaseMissing('page_section', ['page_id' => $page->id, 'stage' => 'draft']);

    $published = $page->sections()->firstOrFail();
    $component->set('pageRows', [['pivot_id' => null, 'section_id' => $published->id, 'slot' => 'hero']])
        ->call('savePageComposition')->assertSuccessful()->assertHasNoErrors()
        ->assertSet('pageRows.0.slot', 'hero')
        ->call('reviewPagePublication')->assertHasNoErrors()
        ->call('publishPage')->assertHasNoErrors();

    $this->assertDatabaseHas('page_section', [
        'page_id' => $page->id, 'stage' => 'draft', 'slot' => 'hero',
        'section_id' => $published->id, 'occurrence_id' => 'f5b6af1c-e217-40e5-b658-5322336e772c',
    ]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'home', 'page_version' => 6]);
});
