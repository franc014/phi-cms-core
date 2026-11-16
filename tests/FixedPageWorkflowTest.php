<?php

use Filament\Actions\Testing\TestAction;
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
use JFA\PhiCmsCore\Resources\Pages\Pages\ManagePageSections;
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

class FixedWorkflowServices extends FixedWorkflowSection
{
    public static function key(): string
    {
        return 'services';
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

class TwoSlotWorkflowPage extends FixedWorkflowPage
{
    public static function composition(): array
    {
        $composition = parent::composition();
        $composition['slots']['footer'] = $composition['slots']['hero'];

        return $composition;
    }
}

function fixedWorkflowFixture(bool $twoSlots = false): Page
{
    $panel = Panel::make()->id('fixed-workflow')->default();
    Filament\Facades\Filament::registerPanel($panel);
    Filament\Facades\Filament::setCurrentPanel($panel);
    Route::get('/fixed-workflow/pages', static fn (): string => 'Pages')
        ->name('filament.fixed-workflow.resources.pages.index');
    Route::get('/fixed-workflow/pages/{record}/sections', static fn (): string => 'Sections')
        ->name('filament.fixed-workflow.resources.pages.manageSections');
    Route::get('/fixed-workflow/sections/{record}/draft', static fn (): string => 'Section')
        ->name('filament.fixed-workflow.resources.sections.draft');
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

    if ($twoSlots) {
        config(['phi-cms.pages' => [TwoSlotWorkflowPage::class]]);
        foreach (['draft', 'published'] as $stage) {
            DB::table('page_section')->insert([
                'page_id' => $pageId, 'section_id' => $sectionId, 'stage' => $stage,
                'slot' => 'footer', 'order_column' => 2, 'occurrence_id' => 'a5b6af1c-e217-40e5-b658-5322336e772c',
            ]);
        }
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
    $component->call('addPageSection', $published->id)->assertSuccessful()->assertHasNoErrors()
        ->assertSet('pageRows.0.slot', 'hero')
        ->call('reviewPagePublication')->assertHasNoErrors()
        ->call('publishPage')->assertHasNoErrors();

    $this->assertDatabaseHas('page_section', [
        'page_id' => $page->id, 'stage' => 'draft', 'slot' => 'hero',
        'section_id' => $published->id, 'occurrence_id' => 'f5b6af1c-e217-40e5-b658-5322336e772c',
    ]);
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'home', 'page_version' => 6]);
});

it('restores a removed original before surviving fixed slots without changing public assignments', function (): void {
    $page = fixedWorkflowFixture();
    config(['phi-cms.pages' => [TwoSlotWorkflowPage::class]]);
    $hero = $page->draftSectionAssignments()->firstOrFail();
    foreach (['draft', 'published'] as $stage) {
        DB::table('page_section')->insert([
            'page_id' => $page->id, 'section_id' => $hero->section_id, 'stage' => $stage,
            'slot' => 'footer', 'order_column' => 2, 'occurrence_id' => 'a5b6af1c-e217-40e5-b658-5322336e772c',
        ]);
    }
    $before = DB::table('page_section')->where('stage', 'published')->get()->map(fn (object $row): array => (array) $row)->all();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('removePageSection', $hero->id)->assertHasNoErrors()
        ->call('addPageSection', $hero->section_id)->assertHasNoErrors()
        ->assertSet('pageRows.0.slot', 'hero')
        ->assertSet('pageRows.1.slot', 'footer');

    $this->assertDatabaseHas('page_section', [
        'page_id' => $page->id, 'stage' => 'draft', 'slot' => 'hero',
        'occurrence_id' => $hero->occurrence_id, 'order_column' => 1,
    ]);
    foreach ($before as $assignment) {
        $this->assertDatabaseHas('page_section', $assignment);
    }
});

it('rejects adding an occupied original or an unrelated fixed Section without partial writes', function (bool $replacement): void {
    $page = fixedWorkflowFixture();
    $sectionId = (int) $page->sections()->firstOrFail()->id;
    if ($replacement) {
        $section = (array) DB::table('sections')->where('id', $sectionId)->first();
        unset($section['id']);
        $section['slug'] = 'replacement';
        $sectionId = DB::table('sections')->insertGetId($section);
    }
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('addPageSection', $sectionId)->assertHasErrors('workflow');

    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $assignment) {
        $this->assertDatabaseHas('page_section', $assignment);
    }
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
})->with([false, true]);

it('lets preview choose each missing original slot for a shared Section before publishing', function (): void {
    $page = fixedWorkflowFixture(twoSlots: true);
    $assignments = $page->draftSectionAssignments()->get();
    $public = DB::table('page_section')->where('stage', 'published')->get()->map(fn (object $row): array => (array) $row)->all();
    $component = Livewire::test(Preview::class, ['pageId' => $page->id]);
    foreach ($assignments as $assignment) {
        $component->call('removePageSection', $assignment->id)->assertHasNoErrors();
    }

    $component->assertSee('Restore hero')->assertSee('Restore footer')
        ->call('addPageSection', $assignments[0]->section_id, 'footer')->assertHasNoErrors()
        ->call('addPageSection', $assignments[0]->section_id, 'hero')->assertHasNoErrors()
        ->assertSet('pageRows.0.slot', 'hero')->assertSet('pageRows.1.slot', 'footer');

    foreach ($public as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
    foreach ($assignments as $index => $assignment) {
        $this->assertDatabaseHas('page_section', [
            'page_id' => $page->id, 'stage' => 'draft', 'slot' => $assignment->slot,
            'occurrence_id' => $assignment->occurrence_id, 'order_column' => $index + 1,
        ]);
    }
    $component->call('reviewPagePublication')->assertHasNoErrors()
        ->call('publishPage')->assertHasNoErrors();
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 6]);
});

it('lets admin choose each missing original slot for a shared Section before publishing', function (): void {
    $page = fixedWorkflowFixture(twoSlots: true);
    config(['phi-cms.sections' => [FixedWorkflowSection::class, FixedWorkflowServices::class]]);
    $assignments = $page->draftSectionAssignments()->get();
    $public = DB::table('page_section')->where('stage', 'published')->get()->map(fn (object $row): array => (array) $row)->all();
    $component = Livewire::test(ManagePageSections::class, ['record' => $page->id]);
    foreach ($assignments as $assignment) {
        $component->callAction(TestAction::make('removeSection')->table($assignment))->assertHasNoErrors();
    }

    foreach (['footer', 'hero'] as $slot) {
        $component->callAction(TestAction::make('addSection')->table(), data: [
            'section_id' => $assignments[0]->section_id, 'slot' => $slot,
        ])->assertHasNoErrors();
    }

    foreach ($public as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
    foreach ($assignments as $index => $assignment) {
        $this->assertDatabaseHas('page_section', [
            'page_id' => $page->id, 'stage' => 'draft', 'slot' => $assignment->slot,
            'occurrence_id' => $assignment->occurrence_id, 'order_column' => $index + 1,
        ]);
    }
    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('reviewPagePublication')->assertHasNoErrors()
        ->call('publishPage')->assertHasNoErrors();
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 6]);
});

it('refuses ambiguous identity-only restoration atomically and lets preview correct the choice', function (): void {
    $page = fixedWorkflowFixture(twoSlots: true);
    $assignments = $page->draftSectionAssignments()->get();
    $component = Livewire::test(Preview::class, ['pageId' => $page->id]);
    foreach ($assignments as $assignment) {
        $component->call('removePageSection', $assignment->id)->assertHasNoErrors();
    }
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    $component->call('addPageSection', $assignments[0]->section_id)
        ->assertHasErrors('workflow')
        ->assertSee('Choose one missing original fixed-slot occurrence to restore.')
        ->assertSet('version', 3)->assertSet('pageRows', []);

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 3]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
    $component->call('addPageSection', $assignments[0]->section_id, 'hero')
        ->assertHasNoErrors()->assertSet('pageRows.0.slot', 'hero');
});

it('rejects forged or repeated explicit fixed-slot restoration without writes', function (string $slot, bool $replacement, bool $remove): void {
    $page = fixedWorkflowFixture();
    $assignment = $page->draftSectionAssignments()->firstOrFail();
    $sectionId = (int) $assignment->section_id;
    if ($replacement) {
        $section = (array) DB::table('sections')->where('id', $sectionId)->first();
        unset($section['id']);
        $section['slug'] = 'replacement';
        $sectionId = DB::table('sections')->insertGetId($section);
    }
    $component = Livewire::test(Preview::class, ['pageId' => $page->id]);
    if ($remove) {
        $component->call('removePageSection', $assignment->id)->assertHasNoErrors();
    }
    $version = $page->fresh()->page_version;
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    $component->call('addPageSection', $sectionId, $slot)->assertHasErrors('workflow');

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => $version]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
})->with([
    'unknown slot' => ['unknown', false, true],
    'unrelated replacement' => ['hero', true, true],
    'occupied original' => ['hero', false, false],
]);

it('rejects stale explicit restoration through native preview and admin actions', function (bool $admin): void {
    $page = fixedWorkflowFixture();
    config(['phi-cms.sections' => [FixedWorkflowSection::class, FixedWorkflowServices::class]]);
    $assignment = $page->draftSectionAssignments()->firstOrFail();
    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('removePageSection', $assignment->id)->assertHasNoErrors();
    $component = $admin
        ? Livewire::test(ManagePageSections::class, ['record' => $page->id])
        : Livewire::test(Preview::class, ['pageId' => $page->id]);
    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('savePageDraft')->assertHasNoErrors();
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    if ($admin) {
        $component->callAction(TestAction::make('addSection')->table(), data: [
            'section_id' => $assignment->section_id, 'slot' => 'hero',
        ])->assertHasErrors('sections');
    } else {
        $component->call('addPageSection', $assignment->section_id, 'hero')->assertHasErrors('workflow');
    }

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 3]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
})->with(['preview' => false, 'admin' => true]);

it('hides unauthorized restoration labels and rechecks selection authority on execution', function (string $ability): void {
    $page = fixedWorkflowFixture();
    $assignment = $page->draftSectionAssignments()->firstOrFail();
    $component = Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('removePageSection', $assignment->id)->assertHasNoErrors()
        ->assertSee('Restore hero');
    Gate::define($ability, fn (): bool => false);
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    Livewire::test(Preview::class, ['pageId' => $page->id])->assertDontSee('Restore hero');
    $component->call('addPageSection', $assignment->section_id, 'hero')->assertForbidden();

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 2]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
})->with(['viewAny', 'view', 'useInPage']);

it('requires an original slot choice in the admin form when a shared identity is ambiguous', function (): void {
    $page = fixedWorkflowFixture(twoSlots: true);
    config(['phi-cms.sections' => [FixedWorkflowSection::class, FixedWorkflowServices::class]]);
    $assignments = $page->draftSectionAssignments()->get();
    $component = Livewire::test(ManagePageSections::class, ['record' => $page->id]);
    foreach ($assignments as $assignment) {
        $component->callAction(TestAction::make('removeSection')->table($assignment))->assertHasNoErrors();
    }
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    $component->callAction(TestAction::make('addSection')->table(), data: [
        'section_id' => $assignments[0]->section_id,
    ])->assertHasFormErrors(['slot' => 'required']);

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 3]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
});

it('rechecks authority after an admin restoration form was displayed', function (string $ability): void {
    $page = fixedWorkflowFixture();
    config(['phi-cms.sections' => [FixedWorkflowSection::class, FixedWorkflowServices::class]]);
    $assignment = $page->draftSectionAssignments()->firstOrFail();
    $component = Livewire::test(ManagePageSections::class, ['record' => $page->id])
        ->callAction(TestAction::make('removeSection')->table($assignment))->assertHasNoErrors()
        ->mountAction(TestAction::make('addSection')->table())
        ->fillForm(['section_id' => $assignment->section_id, 'slot' => 'hero']);
    Gate::define($ability, fn (): bool => false);
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();

    $component->call('callMountedAction');
    if ($ability === 'compose') {
        $component->assertForbidden();
    } else {
        $component->assertHasFormErrors(['section_id']);
    }

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 2]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
})->with(['compose', 'useInPage']);

it('does not silently repair forged existing fixed-slot order', function (): void {
    $page = fixedWorkflowFixture(twoSlots: true);
    $before = DB::table('page_section')->get()->map(fn (object $row): array => (array) $row)->all();
    $hero = $page->draftSectionAssignments()->firstOrFail();

    Livewire::test(Preview::class, ['pageId' => $page->id])
        ->call('movePageSection', $hero->id, 'down')->assertHasErrors('workflow')
        ->assertSee('Fixed slots must retain their declared order.');

    $this->assertDatabaseHas('pages', ['id' => $page->id, 'page_version' => 1]);
    $this->assertDatabaseCount('page_section', count($before));
    foreach ($before as $row) {
        $this->assertDatabaseHas('page_section', $row);
    }
});
