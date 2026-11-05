<?php

namespace JFA\PhiCmsCore\Menus;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JFA\PhiCmsCore\Enums\PageStatus;
use JFA\PhiCmsCore\Enums\SectionStatus;
use JFA\PhiCmsCore\Models\Menu;
use JFA\PhiCmsCore\Models\Page;
use JFA\PhiCmsCore\Models\PageUrl;
use JFA\PhiCmsCore\Models\Section;

final class MenuContent
{
    private const MAX_ITEMS = 100;

    /**
     * Create the declared flat menu as equal draft and public lists.
     *
     * This method is intended for the explicit adoption command. Existing
     * keyed menus are verified and returned without resetting editorial state.
     */
    public function adoptConfiguredMenu(string $key): Menu
    {
        $definition = $this->menuDefinition($key);
        abort_unless(app()->runningInConsole(), 403);

        $existing = Menu::query()->where('key', $key)->first();
        if ($existing !== null) {
            $this->assertAdopted($existing, $key);
            $this->assertDeclarationCurrent($existing, $key);

            return $existing;
        }

        $preflight = $this->preflight($key);
        if (! $preflight['publishable']) {
            throw new MenuConflict("Configured menu [{$key}] has unavailable destinations. Resolve the write-free preflight before adoption.");
        }
        $bindings = $this->resolveBindings($key);
        $rows = $definition['adoption_items'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_ITEMS) {
            throw new MenuConflict("Configured menu [{$key}] must declare an ordered adoption_items list of at most " . self::MAX_ITEMS . ' items.');
        }

        $normalizedRows = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['label', 'destination']) !== []) {
                throw ValidationException::withMessages(["items.{$index}" => 'Each configured item must contain only a label and destination.']);
            }
            $label = $this->validateLabel($row['label'] ?? null, "items.{$index}.label");
            $destination = $this->normalizeConfiguredDestination($row['destination'] ?? null, $bindings, "items.{$index}.destination");
            $itemKey = (string) Str::uuid();
            $normalizedRows[] = [
                'item_key' => $itemKey,
                'label' => $label,
                'destination' => $destination,
            ];
        }

        $bindings['declaration_fingerprint'] = $this->declarationFingerprint($key);
        $slug = $definition['slug'] ?? $key;
        if (! is_string($slug) || ! preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug)) {
            throw new MenuConflict("Configured menu [{$key}] has an invalid slug.");
        }

        try {
            return $this->transaction(function () use ($key, $definition, $bindings, $normalizedRows, $slug): Menu {
                if (Menu::query()->where('key', $key)->exists()) {
                    $existing = Menu::query()->where('key', $key)->firstOrFail();
                    $this->assertAdopted($existing, $key);
                    $this->assertDeclarationCurrent($existing, $key);

                    return $existing;
                }

                if (Menu::query()->where('slug', $slug)->exists()) {
                    throw new MenuConflict("A legacy menu already uses slug [{$slug}]. It will not be repurposed for [{$key}].");
                }

                $this->lockReferences($normalizedRows, $bindings);
                $this->assertBindingsStillMatch($key, $bindings);

                $now = now();
                $menuId = DB::table('menus')->insertGetId([
                    'title' => $definition['label'],
                    'description' => $definition['description'] ?? "Developer-declared {$definition['label']} menu.",
                    'slug' => $slug,
                    'key' => $key,
                    'menu_version' => 1,
                    'adoption_bindings' => json_encode($bindings, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($normalizedRows as $index => $row) {
                    foreach (['draft', 'published'] as $stage) {
                        DB::table('menu_items')->insert([
                            'menu_id' => $menuId,
                            'slug' => $row['item_key'],
                            'label' => $row['label'],
                            'url' => '',
                            'items' => null,
                            'order_column' => $index + 1,
                            'stage' => $stage,
                            'item_key' => $row['item_key'],
                            'destination' => json_encode($row['destination'], JSON_THROW_ON_ERROR),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                return Menu::query()->findOrFail($menuId);
            });
        } catch (QueryException $exception) {
            if ($this->isConcurrencyFailure($exception)) {
                $existing = Menu::query()->where('key', $key)->first();
                if ($existing !== null) {
                    $this->assertAdopted($existing, $key);
                    $this->assertDeclarationCurrent($existing, $key);

                    return $existing;
                }

                throw new MenuConflict('Another menu adoption changed a unique identity. Recheck the inventory before retrying.');
            }

            throw $exception;
        }
    }

    /** Accept validated declaration additions without resetting either editorial stage. */
    public function reconcileConfiguredMenu(string $key, int $expectedVersion): int
    {
        abort_unless(app()->runningInConsole(), 403);
        $menu = $this->adoptedMenu($key);
        $bindings = $this->reconciledBindings($menu, $key);

        return $this->transaction(function () use ($key, $menu, $bindings, $expectedVersion): int {
            $rows = $this->draftRows($menu);
            $lockRows = array_merge($rows, $this->publicRows($menu));
            foreach ($bindings['fixed_pages'] as $binding) {
                foreach (array_merge([null], array_keys($binding['anchors'])) as $anchor) {
                    $lockRows[] = ['destination' => ['kind' => 'page', 'page_id' => $binding['page_id'], 'anchor' => $anchor]];
                }
            }
            $this->lockReferences($lockRows, $bindings);
            $fresh = $this->lockMenu($menu, $rows);
            $this->assertVersion($fresh, $expectedVersion);
            if ($fresh->adoption_bindings !== $menu->adoption_bindings
                || $this->reconciledBindings($fresh, $key) !== $bindings) {
                throw new MenuConflict('Menu declaration bindings changed during reconciliation. Reload and retry.');
            }
            if ($fresh->adoption_bindings === $bindings) {
                return $expectedVersion;
            }

            DB::table('menus')->where('id', $fresh->getKey())->update([
                'adoption_bindings' => json_encode($bindings, JSON_THROW_ON_ERROR),
                'menu_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            session()->forget($this->reviewKey($fresh));

            return $expectedVersion + 1;
        });
    }

    /**
     * @return list<array{id: string, label: string, href: string}>
     */
    public function published(string $key): array
    {
        $menu = $this->adoptedMenu($key);
        $resolved = $this->withConsistentReadSnapshot(function () use ($menu, $key): array {
            return $this->resolveRows($menu, $key, $this->publicRows($menu), preview: false);
        });

        return array_values(array_map(
            static fn (array $row): array => [
                'id' => (string) $row['item_key'],
                'label' => (string) $row['label'],
                'href' => (string) $row['resolution']['href'],
            ],
            array_filter($resolved, static fn (array $row): bool => $row['resolution']['status'] === 'available' || $row['resolution']['status'] === 'untracked'),
        ));
    }

    /** @return array{items: list<array<string, mixed>>, feedback: list<array<string, mixed>>, version: int} */
    public function preview(string $key): array
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('preview', $menu);
        $this->authorizeMenu('viewDraft', $menu, $actor);
        $this->markPrivateResponse();
        $snapshot = $this->withConsistentReadSnapshot(function () use ($key, $actor): array {
            $menu = $this->adoptedMenu($key);
            $actor = $this->authorizeMenu('preview', $menu, $actor);
            $this->authorizeMenu('viewDraft', $menu, $actor);
            $this->assertDeclarationCurrent($menu, $key);

            return [
                'menu' => $menu,
                'rows' => $this->resolveRows($menu, $key, $this->draftRows($menu), preview: true, actor: $actor),
            ];
        });
        /** @var array{menu: Menu, rows: list<array<string, mixed>>} $snapshot */

        return [
            'items' => array_map(fn (array $row): array => $this->previewRow($row), $snapshot['rows']),
            'feedback' => array_values(array_filter(array_map(
                fn (array $row): ?array => $row['resolution']['status'] === 'unavailable'
                    ? ['item_key' => (string) $row['item_key'], 'message' => $row['resolution']['message']]
                    : null,
                $snapshot['rows'],
            ))),
            'version' => (int) $snapshot['menu']->menu_version,
        ];
    }

    /** @return list<array{item_key: string, label: string, destination: array<string, mixed>|null}> */
    public function draftItems(string $key): array
    {
        $menu = $this->adoptedMenu($key);
        $this->authorizeMenu('viewDraft', $menu);
        $this->markPrivateResponse();
        $this->assertDeclarationCurrent($menu, $key);

        return array_map(
            static fn (array $row): array => [
                'item_key' => (string) $row['item_key'],
                'label' => (string) $row['label'],
                'destination' => $row['destination'],
            ],
            $this->draftRows($menu),
        );
    }

    /**
     * Save the complete ordered draft list. A null item_key asks the server to
     * issue a new stable identity; non-null identities must belong to this
     * menu's current draft stage.
     *
     * @param  list<array{item_key: string|null, label: string, destination: array<string, mixed>}>  $rows
     */
    public function saveDraft(string $key, array $rows, int $expectedVersion): int
    {
        abort_unless(config('phi-cms.editing', false), 403);
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('update', $menu);
        $this->authorizeMenu('viewDraft', $menu, $actor);
        $this->markPrivateResponse();
        $validatedRows = $this->validateRows($rows);
        $this->assertDeclarationCurrent($menu, $key);

        return $this->transaction(function () use ($key, $menu, $actor, $validatedRows, $expectedVersion): int {
            $oldRows = $this->draftRows($menu);
            $lockRows = array_merge($oldRows, $validatedRows);
            $this->lockReferences($lockRows, $menu->adoption_bindings);
            $fresh = $this->lockMenu($menu, $oldRows);
            $actor = $this->authorizeMenu('update', $fresh, $actor);
            $this->authorizeMenu('viewDraft', $fresh, $actor);
            $this->assertVersion($fresh, $expectedVersion);
            $this->assertDeclarationCurrent($fresh, $key);

            $current = collect($this->draftRows($fresh))->keyBy('item_key');
            $normalized = [];
            $seen = [];
            foreach ($validatedRows as $index => $row) {
                $itemKey = $row['item_key'];
                if ($itemKey !== null && ! $current->has($itemKey)) {
                    throw ValidationException::withMessages(["items.{$index}.item_key" => 'This row does not belong to the current menu draft. Reload the menu before saving.']);
                }
                $itemKey ??= (string) Str::uuid();
                if (isset($seen[$itemKey])) {
                    throw ValidationException::withMessages(["items.{$index}.item_key" => 'A menu item identity may only appear once.']);
                }
                $seen[$itemKey] = true;
                $normalized[] = [
                    'item_key' => $itemKey,
                    'label' => $row['label'],
                    'destination' => $this->normalizeDestination($row['destination'], $fresh, $key, $actor, "items.{$index}.destination"),
                ];
            }

            $now = now();
            foreach ($normalized as $index => $row) {
                $data = [
                    'slug' => $row['item_key'],
                    'label' => $row['label'],
                    'url' => '',
                    'items' => null,
                    'order_column' => $index + 1,
                    'destination' => json_encode($row['destination'], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];
                $existing = $current->get($row['item_key']);
                if ($existing === null) {
                    DB::table('menu_items')->insert($data + [
                        'menu_id' => $fresh->getKey(),
                        'stage' => 'draft',
                        'item_key' => $row['item_key'],
                        'created_at' => $now,
                    ]);
                } else {
                    DB::table('menu_items')->where('id', $existing['id'])->update($data);
                }
            }

            $delete = DB::table('menu_items')->where('menu_id', $fresh->getKey())->where('stage', 'draft');
            if ($normalized !== []) {
                $delete->whereNotIn('item_key', array_column($normalized, 'item_key'));
            }
            $delete->delete();
            DB::table('menus')->where('id', $fresh->getKey())->update([
                'menu_version' => $expectedVersion + 1,
                'updated_at' => $now,
            ]);
            session()->forget($this->reviewKey($fresh));

            return $expectedVersion + 1;
        });
    }

    /** @return array<string, mixed> */
    public function review(string $key, int $expectedVersion): array
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('publish', $menu);
        $this->authorizeMenu('viewDraft', $menu, $actor);
        $this->markPrivateResponse();

        return $this->transaction(function () use ($key, $menu, $actor, $expectedVersion): array {
            $rows = $this->draftRows($menu);
            $this->lockReferences($rows, $menu->adoption_bindings);
            $fresh = $this->lockMenu($menu, $rows);
            $actor = $this->authorizeMenu('publish', $fresh, $actor);
            $this->authorizeMenu('viewDraft', $fresh, $actor);
            $this->assertVersion($fresh, $expectedVersion);
            $this->assertDeclarationCurrent($fresh, $key);
            $review = $this->buildReview($fresh, $key, $this->draftRows($fresh), $actor);
            $token = (string) Str::random(64);
            session()->put($this->reviewKey($fresh), [
                'actor' => (string) $actor->getAuthIdentifier(),
                'version' => $expectedVersion,
                'token' => $token,
                'fingerprint' => $review['fingerprint'],
            ]);

            return $review + ['version' => $expectedVersion, 'token' => $token];
        });
    }

    /** @param array<string, mixed> $review */
    public function authorizeRetainedReview(string $key, array $review): void
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('publish', $menu);
        $this->authorizeMenu('viewDraft', $menu, $actor);
        $this->markPrivateResponse();
        $targets = [];
        $items = $review['items'] ?? [];
        foreach ($review['changes'] ?? [] as $change) {
            foreach (['before', 'after'] as $stage) {
                if (is_array($change[$stage] ?? null)) {
                    $items[] = $change[$stage];
                }
            }
        }
        foreach ($items as $item) {
            if (($item['destination']['kind'] ?? null) === 'page') {
                $pageId = (int) $item['destination']['page_id'];
                $targets[$pageId] = [
                    'draft' => ($targets[$pageId]['draft'] ?? false) || ($item['status'] ?? null) === 'draft',
                    'requires_page' => ($targets[$pageId]['requires_page'] ?? false)
                        || ($item['status'] ?? null) !== 'unavailable'
                        || ($item['target_label'] ?? null) !== null
                        || ($item['proposed_path'] ?? null) !== null,
                ];
            }
        }
        $pages = Page::query()->whereIn('id', array_keys($targets))->get()->keyBy('id');
        foreach ($targets as $pageId => $target) {
            $page = $pages->get($pageId);
            if ($page === null) {
                abort_if($target['requires_page'], 403);

                continue;
            }
            $this->authorizePageTarget($actor, $page, $target['draft'] || $page->status !== PageStatus::PUBLISHED);
        }
    }

    public function publish(string $key, int $expectedVersion, string $reviewToken): int
    {
        abort_unless(config('phi-cms.editing', false), 403);
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('publish', $menu);
        $this->authorizeMenu('viewDraft', $menu, $actor);
        $this->markPrivateResponse();

        return $this->transaction(function () use ($key, $menu, $actor, $expectedVersion, $reviewToken): int {
            $rows = $this->draftRows($menu);
            $this->lockReferences($rows, $menu->adoption_bindings);
            $fresh = $this->lockMenu($menu, $rows);
            $actor = $this->authorizeMenu('publish', $fresh, $actor);
            $this->authorizeMenu('viewDraft', $fresh, $actor);
            $this->assertVersion($fresh, $expectedVersion);
            $this->assertDeclarationCurrent($fresh, $key);

            $reviewState = session()->get($this->reviewKey($fresh));
            if (! is_array($reviewState)
                || ($reviewState['actor'] ?? null) !== (string) $actor->getAuthIdentifier()
                || ($reviewState['version'] ?? null) !== $expectedVersion
                || ! is_string($reviewState['token'] ?? null)
                || ! hash_equals($reviewState['token'], $reviewToken)) {
                throw new MenuConflict('This publication review is stale or belongs to another manager. Review the saved menu again.');
            }

            $currentReview = $this->buildReview($fresh, $key, $this->draftRows($fresh), $actor);
            if (! $currentReview['publishable']) {
                throw ValidationException::withMessages(['menu' => 'Resolve unavailable or unpublished destinations before publishing this menu.']);
            }
            if (! hash_equals((string) $reviewState['fingerprint'], (string) $currentReview['fingerprint'])) {
                throw new MenuConflict('A destination path, availability, membership, or developer declaration changed after review. Review the menu again.');
            }

            $published = collect($this->publicRows($fresh))->keyBy('item_key');
            $kept = [];
            $now = now();
            foreach ($this->draftRows($fresh) as $index => $row) {
                $itemKey = (string) $row['item_key'];
                $kept[] = $itemKey;
                $data = [
                    'slug' => $itemKey,
                    'label' => $row['label'],
                    'url' => '',
                    'items' => null,
                    'order_column' => $index + 1,
                    'destination' => json_encode($row['destination'], JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ];
                $current = $published->get($itemKey);
                if ($current === null) {
                    DB::table('menu_items')->insert($data + [
                        'menu_id' => $fresh->getKey(),
                        'stage' => 'published',
                        'item_key' => $itemKey,
                        'created_at' => $now,
                    ]);
                } else {
                    DB::table('menu_items')->where('id', $current['id'])->update($data);
                }
            }

            $delete = DB::table('menu_items')->where('menu_id', $fresh->getKey())->where('stage', 'published');
            if ($kept !== []) {
                $delete->whereNotIn('item_key', $kept);
            }
            $delete->delete();
            DB::table('menus')->where('id', $fresh->getKey())->update([
                'menu_version' => $expectedVersion + 1,
                'updated_at' => $now,
            ]);
            session()->forget($this->reviewKey($fresh));

            return $expectedVersion + 1;
        });
    }

    /** @return list<array<string, mixed>> */
    public function destinationChoices(string $key, ?string $search = null): array
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('viewDraft', $menu);
        $this->authorizeMenu('viewAny', $menu, $actor);
        $this->markPrivateResponse();
        $this->assertDeclarationCurrent($menu, $key);
        $query = Page::query()->orderBy('title')->orderBy('id');
        if (is_string($search) && $search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . addcslashes($search, '%_\\') . '%')
                    ->orWhere('slug', 'like', '%' . addcslashes($search, '%_\\') . '%');
            });
        }

        $choices = [];
        foreach ($query->limit(50)->get() as $page) {
            if (! $this->mayUsePage($actor, $page, $page->status !== PageStatus::PUBLISHED)) {
                continue;
            }
            $resolution = $this->resolvePage($menu, $key, (int) $page->getKey(), null, preview: true, actor: $actor);
            if ($resolution['status'] === 'unavailable') {
                continue;
            }
            $choices[] = [
                'destination' => ['kind' => 'page', 'page_id' => (int) $page->getKey(), 'anchor' => null],
                'label' => $this->pageChoiceLabel($resolution),
                'status' => $resolution['status'],
                'href' => $resolution['href'],
            ];
        }

        foreach ($this->fixedRouteChoices($key, $actor) as $choice) {
            $choices[] = $choice;
        }

        return $choices;
    }

    /** @return array{destination: array<string, mixed>, label: string, status: string, href: string|null}|null */
    public function pageChoice(string $key, int $pageId): ?array
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('viewDraft', $menu);
        $this->markPrivateResponse();
        $this->assertDeclarationCurrent($menu, $key);
        $page = Page::query()->find($pageId);
        if ($page === null || ! $this->mayUsePage($actor, $page, $page->status !== PageStatus::PUBLISHED)) {
            return null;
        }
        $resolution = $this->resolvePage($menu, $key, $pageId, null, preview: true, actor: $actor);
        if ($resolution['status'] === 'unavailable') {
            return null;
        }

        return [
            'destination' => ['kind' => 'page', 'page_id' => $pageId, 'anchor' => null],
            'label' => $this->pageChoiceLabel($resolution),
            'status' => $resolution['status'],
            'href' => $resolution['href'],
        ];
    }

    /** @return array<string, string> */
    public function anchorChoices(string $key, int $pageId): array
    {
        $menu = $this->adoptedMenu($key);
        $actor = $this->authorizeMenu('viewDraft', $menu);
        $this->markPrivateResponse();
        $this->assertDeclarationCurrent($menu, $key);
        $page = Page::query()->find($pageId);
        if ($page === null || ! $this->mayUsePage($actor, $page, $page->status !== PageStatus::PUBLISHED)) {
            return [];
        }
        $binding = $this->fixedPageBinding($menu, $pageId);
        if ($binding === null) {
            return [];
        }

        $choices = [];
        foreach (($binding['anchors'] ?? []) as $anchor => $anchorBinding) {
            $resolution = $this->anchorDefinition($menu, $page, $anchor);
            if (! $resolution['available']) {
                continue;
            }
            $definition = config("phi-cms.navigation.fixed_pages.{$binding['key']}.anchors.{$anchor}");
            $choices[$anchor] = $definition['label'] . ' — #' . $anchor;
        }

        return $choices;
    }

    /** @return array<string, mixed> */
    public function preflight(string $key, bool $reconcile = false): array
    {
        $definition = $this->menuDefinition($key);
        $existing = Menu::query()->where('key', $key)->first();
        if ($existing !== null) {
            $this->assertAdopted($existing, $key);
            if ($reconcile) {
                $this->reconciledBindings($existing, $key);
            } else {
                $this->assertDeclarationCurrent($existing, $key);
            }

            return [
                'key' => $key,
                'label' => $definition['label'],
                'menu_exists' => true,
                'existing_menu_id' => $existing->getKey(),
                'version' => (int) $existing->menu_version,
                'item_count' => count($this->publicRows($existing)),
                'publishable' => true,
                'items' => [],
                'feedback' => [],
            ];
        }
        if ($reconcile) {
            throw new MenuConflict("Menu [{$key}] must exist before declaration reconciliation.");
        }
        $bindings = $this->resolveBindings($key);
        $configuredRows = $definition['adoption_items'] ?? null;
        if (! is_array($configuredRows) || ! array_is_list($configuredRows) || count($configuredRows) > self::MAX_ITEMS) {
            throw new MenuConflict("Configured menu [{$key}] must declare an ordered adoption_items list of at most " . self::MAX_ITEMS . ' items.');
        }
        $rows = [];
        foreach ($configuredRows as $index => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['label', 'destination']) !== []) {
                throw ValidationException::withMessages(["items.{$index}" => 'Each configured item must contain only a label and destination.']);
            }
            $rows[] = [
                'item_key' => 'preflight-' . ($index + 1),
                'label' => $this->validateLabel($row['label'] ?? null, "items.{$index}.label"),
                'destination' => $this->normalizeConfiguredDestination($row['destination'] ?? null, $bindings, "items.{$index}.destination"),
                'order_column' => $index + 1,
            ];
        }

        $menu = (new Menu)->forceFill(['key' => $key, 'menu_version' => 1, 'adoption_bindings' => $bindings]);
        $resolved = $this->resolveRows($menu, $key, $rows, preview: false);
        $unavailable = array_filter($resolved, static fn (array $row): bool => ! in_array($row['resolution']['status'], ['available', 'untracked'], true));

        return [
            'key' => $key,
            'label' => $definition['label'],
            'menu_exists' => $existing !== null,
            'existing_menu_id' => $existing?->getKey(),
            'item_count' => count($rows),
            'publishable' => $unavailable === [],
            'items' => array_map(fn (array $row): array => $this->previewRow($row), $resolved),
            'feedback' => array_values(array_map(
                static fn (array $row): array => ['label' => $row['label'], 'message' => $row['resolution']['message']],
                $unavailable,
            )),
        ];
    }

    /** @return array<string, mixed> */
    public function inventory(string $key): array
    {
        $definition = $this->menuDefinition($key);
        $menu = Menu::query()->where('key', $key)->first();

        return [
            'key' => $key,
            'configured_label' => $definition['label'],
            'configured_items' => $definition['adoption_items'] ?? [],
            'menu' => $menu?->only(['id', 'title', 'description', 'slug', 'key', 'menu_version']),
            'draft_rows' => $menu === null ? [] : $this->draftRows($menu),
            'published_rows' => $menu === null ? [] : $this->publicRows($menu),
        ];
    }

    /** @return array<string, mixed> */
    private function menuDefinition(string $key): array
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $key)) {
            throw new MenuConflict('The configured menu key is invalid.');
        }
        $definition = config("phi-cms.navigation.menus.{$key}");
        if (! is_array($definition)
            || ! is_string($definition['label'] ?? null)
            || $definition['label'] === '') {
            throw new MenuConflict("Menu [{$key}] is not declared in phi-cms.navigation.menus.");
        }

        return $definition;
    }

    private function adoptedMenu(string $key): Menu
    {
        $menu = Menu::query()->where('key', $key)->first();
        if ($menu === null) {
            abort(503, "Configured menu [{$key}] has not been adopted. Run the write-free menu inventory, then explicitly adopt it before enabling this location.");
        }
        $this->assertAdopted($menu, $key);

        return $menu;
    }

    private function assertAdopted(Menu $menu, string $key): void
    {
        if ((string) $menu->key !== $key || $menu->menu_version === null || ! is_array($menu->adoption_bindings)) {
            throw new MenuConflict("Menu [{$key}] is not an intact adopted menu. Legacy menus are never repurposed implicitly.");
        }
    }

    private function assertDeclarationCurrent(Menu $menu, string $key): void
    {
        $this->assertAdopted($menu, $key);
        $stored = $menu->adoption_bindings['declaration_fingerprint'] ?? null;
        if (! is_string($stored) || ! hash_equals($stored, $this->declarationFingerprint($key))) {
            throw new MenuConflict("The developer declaration for menu [{$key}] changed. Reconcile its fixed route and anchor bindings before continuing.");
        }
    }

    /** @return array<string, mixed> */
    private function reconciledBindings(Menu $menu, string $key): array
    {
        $bindings = $this->resolveBindings($key);
        foreach ($menu->adoption_bindings['fixed_links'] ?? [] as $linkKey => $binding) {
            if (($bindings['fixed_links'][$linkKey] ?? null) !== $binding) {
                throw new MenuConflict("Fixed route [{$linkKey}] cannot be removed or rebound by declaration reconciliation.");
            }
        }
        foreach ($menu->adoption_bindings['fixed_pages'] ?? [] as $pageKey => $binding) {
            $candidate = $bindings['fixed_pages'][$pageKey] ?? null;
            $anchors = $binding['anchors'];
            unset($binding['anchors']);
            if (! is_array($candidate) || array_diff_assoc($binding, $candidate) !== []) {
                throw new MenuConflict("Fixed Page [{$pageKey}] cannot be removed or rebound by declaration reconciliation.");
            }
            foreach ($anchors as $anchor => $anchorBinding) {
                if (($candidate['anchors'][$anchor] ?? null) !== $anchorBinding) {
                    throw new MenuConflict("Anchor [{$pageKey}.{$anchor}] cannot be removed or rebound by declaration reconciliation.");
                }
            }
        }
        $bindings['declaration_fingerprint'] = $this->declarationFingerprint($key);

        return $bindings;
    }

    /** @return array<string, mixed> */
    private function resolveBindings(string $key): array
    {
        $navigation = config('phi-cms.navigation', []);
        if (! is_array($navigation)) {
            throw new MenuConflict('phi-cms.navigation must be an array.');
        }
        $fixedPages = $navigation['fixed_pages'] ?? [];
        $fixedLinks = $navigation['fixed_links'] ?? [];
        if (! is_array($fixedPages) || ! is_array($fixedLinks)) {
            throw new MenuConflict('Fixed Page and fixed link declarations must be arrays.');
        }

        $bindings = ['fixed_pages' => [], 'fixed_links' => []];
        foreach ($fixedPages as $pageKey => $declaration) {
            if (! is_string($pageKey) || ! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $pageKey)
                || ! is_array($declaration)
                || ! is_string($declaration['page_slug'] ?? null)
                || ! is_string($declaration['route'] ?? null)
                || ! Route::has($declaration['route'])) {
                throw new MenuConflict("Fixed Page declaration [{$pageKey}] is invalid or its host route is missing.");
            }
            $candidates = Page::query()->where('slug', $declaration['page_slug'])->get();
            if ($candidates->count() !== 1) {
                throw new MenuConflict("Fixed Page [{$pageKey}] must resolve to exactly one Page identity.");
            }
            $page = $candidates->first();
            $anchors = $declaration['anchors'] ?? [];
            if (! is_array($anchors) || (array_is_list($anchors) && $anchors !== [])) {
                throw new MenuConflict("Fixed Page [{$pageKey}] anchors must be a keyed map of rendered HTML IDs.");
            }
            $anchorBindings = [];
            foreach ($anchors as $anchor => $anchorDefinition) {
                if (! is_string($anchor) || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*\z/D', $anchor)
                    || ! is_array($anchorDefinition)
                    || ! is_string($anchorDefinition['label'] ?? null)
                    || ! is_string($anchorDefinition['section_slug'] ?? null)) {
                    throw new MenuConflict("Anchor declaration [{$pageKey}.{$anchor}] is invalid.");
                }
                $sections = Section::query()->where('slug', $anchorDefinition['section_slug'])->get();
                if ($sections->count() !== 1) {
                    throw new MenuConflict("Anchor [{$pageKey}.{$anchor}] must resolve to exactly one Section identity.");
                }
                $section = $sections->first();
                $this->assertAnchorMembership($page, $section, $anchorDefinition, "{$pageKey}.{$anchor}");
                $anchorBindings[$anchor] = [
                    'section_id' => (int) $section->getKey(),
                    'section_slug' => $anchorDefinition['section_slug'],
                    'slot' => $anchorDefinition['slot'] ?? null,
                ];
            }
            $bindings['fixed_pages'][$pageKey] = [
                'page_id' => (int) $page->getKey(),
                'page_slug' => $declaration['page_slug'],
                'route' => $declaration['route'],
                'anchors' => $anchorBindings,
            ];
        }

        foreach ($fixedLinks as $linkKey => $declaration) {
            if (! is_string($linkKey) || ! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $linkKey)
                || ! is_array($declaration)
                || ! is_string($declaration['label'] ?? null)
                || ! is_string($declaration['route'] ?? null)
                || ! Route::has($declaration['route'])) {
                throw new MenuConflict("Fixed route declaration [{$linkKey}] is invalid or its host route is missing.");
            }

            try {
                route($declaration['route'], [], false);
            } catch (\Throwable $exception) {
                throw new MenuConflict("Fixed route [{$linkKey}] must not require parameters.", previous: $exception);
            }
            $bindings['fixed_links'][$linkKey] = ['route' => $declaration['route']];
        }

        return $bindings;
    }

    private function assertAnchorMembership(Page $page, Section $section, array $definition, string $anchor): void
    {
        if ($section->status !== SectionStatus::ACTIVE) {
            throw new MenuConflict("Anchor [{$anchor}] is not on an active Section.");
        }
        if ($section->content_version !== null && $section->published_content === null) {
            throw new MenuConflict("Anchor [{$anchor}] has no published Section content.");
        }
        $assignment = DB::table('page_section')
            ->where('page_id', $page->getKey())
            ->where('section_id', $section->getKey())
            ->where('stage', 'published')
            ->first();
        if ($assignment === null) {
            throw new MenuConflict("Anchor [{$anchor}] is not a published member of its declared Page.");
        }
        if ($page->page_version !== null) {
            $slot = $definition['slot'] ?? null;
            if (! is_string($slot) || $slot === '' || $assignment->slot !== $slot) {
                throw new MenuConflict("Adopted Page anchor [{$anchor}] must bind to its declared published fixed slot.");
            }
        }
    }

    private function declarationFingerprint(string $key): string
    {
        $menu = $this->menuDefinition($key);
        unset($menu['preview_route']);
        $navigation = config('phi-cms.navigation', []);
        $declaration = [
            'menu' => $menu,
            'page_route' => is_array($navigation) ? ($navigation['page_route'] ?? null) : null,
            'page_route_parameter' => is_array($navigation) ? ($navigation['page_route_parameter'] ?? 'slug') : 'slug',
            'fixed_pages' => is_array($navigation) ? ($navigation['fixed_pages'] ?? []) : [],
            'fixed_links' => is_array($navigation) ? ($navigation['fixed_links'] ?? []) : [],
        ];

        return hash('sha256', json_encode($declaration, JSON_THROW_ON_ERROR));
    }

    private function assertBindingsStillMatch(string $key, array $bindings): void
    {
        $resolved = $this->resolveBindings($key);
        unset($bindings['declaration_fingerprint']);

        if ($resolved !== $bindings) {
            throw new MenuConflict('A fixed Page, Section, membership, or route binding changed during menu adoption. Re-run the write-free inventory.');
        }
    }

    /** @return array{kind: string, page_id: int, anchor: string|null}|array{kind: string, key: string}|array{kind: string, url: string} */
    private function normalizeConfiguredDestination(mixed $destination, array $bindings, string $path): array
    {
        if (! is_array($destination) || ! is_string($destination['kind'] ?? null)) {
            throw ValidationException::withMessages([$path => 'Choose a declared Page, fixed route, or custom URL destination.']);
        }
        if ($destination['kind'] === 'page_slug') {
            if (array_diff(array_keys($destination), ['kind', 'page_slug', 'anchor']) !== [] || ! is_string($destination['page_slug'] ?? null)) {
                throw ValidationException::withMessages([$path => 'The configured Page destination is invalid.']);
            }
            $fixedKey = null;
            foreach ($bindings['fixed_pages'] as $candidateKey => $binding) {
                if ($binding['page_slug'] === $destination['page_slug']) {
                    $fixedKey = $candidateKey;

                    break;
                }
            }
            $page = $fixedKey === null
                ? Page::query()->where('slug', $destination['page_slug'])->first()
                : Page::query()->find($bindings['fixed_pages'][$fixedKey]['page_id']);
            if ($page === null) {
                throw ValidationException::withMessages([$path => 'The configured Page identity is unavailable.']);
            }

            return $this->normalizeDestination([
                'kind' => 'page',
                'page_id' => (int) $page->getKey(),
                'anchor' => $destination['anchor'] ?? null,
            ], null, null, null, $path, validateAccess: false);
        }

        return $this->normalizeDestination($destination, null, null, null, $path, validateAccess: false);
    }

    private function validateRows(array $rows): array
    {
        if (! array_is_list($rows) || count($rows) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['items' => 'Menu items must be an ordered list of at most ' . self::MAX_ITEMS . ' rows.']);
        }
        $validated = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['item_key', 'label', 'destination']) !== []) {
                throw ValidationException::withMessages(["items.{$index}" => 'Each menu row must contain only item_key, label and destination.']);
            }
            $itemKey = $row['item_key'] ?? null;
            if ($itemKey !== null && (! is_string($itemKey) || ! Str::isUuid($itemKey))) {
                throw ValidationException::withMessages(["items.{$index}.item_key" => 'The menu item identity is invalid.']);
            }
            if (! is_array($row['destination'] ?? null)) {
                throw ValidationException::withMessages(["items.{$index}.destination" => 'Choose a valid menu destination.']);
            }
            $validated[] = [
                'item_key' => $itemKey,
                'label' => $this->validateLabel($row['label'] ?? null, "items.{$index}.label"),
                'destination' => $row['destination'],
            ];
        }

        return $validated;
    }

    private function validateLabel(mixed $label, string $path): string
    {
        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
            throw ValidationException::withMessages([$path => 'Menu labels must be non-empty text of 255 characters or fewer.']);
        }

        return $label;
    }

    private function normalizeDestination(
        array $destination,
        ?Menu $menu,
        ?string $menuKey,
        ?Authenticatable $actor,
        string $path,
        bool $validateAccess = true,
    ): array {
        $kind = $destination['kind'] ?? null;
        if ($kind === 'page') {
            if (array_diff(array_keys($destination), ['kind', 'page_id', 'anchor']) !== []
                || ! is_int($destination['page_id'] ?? null) || $destination['page_id'] < 1
                || (! is_string($destination['anchor'] ?? null) && ($destination['anchor'] ?? null) !== null)) {
                throw ValidationException::withMessages([$path => 'Page destinations must contain a Page identity and a registered anchor or null.']);
            }
            $page = Page::query()->find($destination['page_id']);
            if ($page === null) {
                throw ValidationException::withMessages([$path => 'The selected Page is unavailable.']);
            }
            if ($validateAccess) {
                abort_if($menu === null || $menuKey === null || $actor === null, 403);
                $isDraft = $page->status !== PageStatus::PUBLISHED;
                if (! $this->mayUsePage($actor, $page, $isDraft)) {
                    throw ValidationException::withMessages([$path => 'You cannot use this Page as a menu destination.']);
                }
                $href = $this->pageHref($menu, $page, $destination['anchor'], validateAnchor: true);
                if ($href === null) {
                    throw ValidationException::withMessages([$path => 'No public route is declared for this Page.']);
                }
            }

            return ['kind' => 'page', 'page_id' => (int) $page->getKey(), 'anchor' => $destination['anchor']];
        }
        if ($kind === 'fixed') {
            if (array_diff(array_keys($destination), ['kind', 'key']) !== [] || ! is_string($destination['key'] ?? null)
                || ! is_array(config('phi-cms.navigation.fixed_links', []))
                || ! array_key_exists($destination['key'], config('phi-cms.navigation.fixed_links', []))) {
                throw ValidationException::withMessages([$path => 'Choose a developer-declared parameter-free host route.']);
            }

            return ['kind' => 'fixed', 'key' => $destination['key']];
        }
        if ($kind === 'custom') {
            if (array_diff(array_keys($destination), ['kind', 'url']) !== [] || ! is_string($destination['url'] ?? null) || ! $this->isSafeCustomUrl($destination['url'])) {
                throw ValidationException::withMessages([$path => 'Use an HTTP(S) URL without credentials or a safe single-leading-slash site path.']);
            }

            return ['kind' => 'custom', 'url' => $destination['url']];
        }

        throw ValidationException::withMessages([$path => 'Choose a declared Page, fixed route, or custom URL destination.']);
    }

    private function isSafeCustomUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }
        $decoded = rawurldecode(rawurldecode($url));
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) === 1 || preg_match('/%(?![0-9a-fA-F]{2})/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        if (array_key_exists('scheme', $parts)) {
            if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
                || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
                return false;
            }

            return true;
        }
        if (array_key_exists('host', $parts) || array_key_exists('port', $parts)
            || ! is_string($parts['path'] ?? null)
            || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return false;
        }
        $decodedPath = rawurldecode(rawurldecode($parts['path']));

        return str_starts_with($decodedPath, '/') && ! str_starts_with($decodedPath, '//');
    }

    private function authorizeMenu(string $ability, Menu $menu, ?Authenticatable $actor = null): Authenticatable
    {
        $expectedIdentifier = $actor?->getAuthIdentifier();
        $actor = auth()->user()?->fresh();
        abort_if(! $actor instanceof Authenticatable, 403);
        abort_if($expectedIdentifier !== null && (string) $expectedIdentifier !== (string) $actor->getAuthIdentifier(), 403);
        $fresh = $menu->exists ? Menu::query()->findOrFail($menu->getKey()) : $menu;
        Gate::forUser($actor)->authorize($ability, $fresh);

        return $actor;
    }

    private function markPrivateResponse(): void
    {
        request()->attributes->set('phi-cms.private', true);
    }

    private function mayUsePage(Authenticatable $actor, Page $page, bool $draft): bool
    {
        $gate = Gate::forUser($actor);
        if (! $gate->allows('viewAny', Page::class)
            || ! $gate->allows('view', $page)
            || ! $gate->allows('useInMenu', $page)) {
            return false;
        }
        if ($draft && (! $gate->allows('preview', $page) || ! $gate->allows('viewDraft', $page))) {
            return false;
        }

        return true;
    }

    private function authorizePageTarget(Authenticatable $actor, Page $page, bool $draft): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('viewAny', Page::class);
        $gate->authorize('view', $page);
        $gate->authorize('useInMenu', $page);

        if ($draft) {
            $gate->authorize('preview', $page);
            $gate->authorize('viewDraft', $page);
        }
    }

    private function pageHref(Menu $menu, Page $page, ?string $anchor, bool $validateAnchor, array $snapshot = []): ?string
    {
        if ($validateAnchor && $anchor !== null && ! $this->anchorDefinition($menu, $page, $anchor, $snapshot)['available']) {
            throw ValidationException::withMessages(['destination.anchor' => 'Choose a developer-exposed anchor that belongs to this Page.']);
        }
        $fixedPage = $this->fixedPageBinding($menu, $page->getKey());
        if ($fixedPage !== null) {
            $declaration = config("phi-cms.navigation.fixed_pages.{$fixedPage['key']}");
            if (! is_array($declaration)
                || ($declaration['route'] ?? null) !== $fixedPage['route']
                || ($declaration['page_slug'] ?? null) !== $fixedPage['page_slug']
                || ! Route::has($fixedPage['route'])) {
                return null;
            }

            try {
                $href = route($fixedPage['route'], [], false);
            } catch (\Throwable) {
                return null;
            }
        } else {
            $routeName = config('phi-cms.navigation.page_route');
            $parameter = config('phi-cms.navigation.page_route_parameter', 'slug');
            if (! is_string($routeName) || ! Route::has($routeName) || ! is_string($parameter) || $parameter === '') {
                return null;
            }
            $slug = $page->status === PageStatus::PUBLISHED
                ? $this->currentPublicSlug($page, $snapshot)
                : (is_array($page->draft_fields) ? ($page->draft_fields['slug'] ?? null) : null);
            if (! is_string($slug) || $slug === '') {
                return null;
            }

            try {
                $href = route($routeName, [$parameter => $slug], false);
            } catch (\Throwable) {
                return null;
            }
        }

        return $anchor === null ? $href : $href . '#' . rawurlencode($anchor);
    }

    /** @return array{available: bool, section_id: int|null, message: string|null} */
    private function anchorDefinition(Menu $menu, Page $page, string $anchor, ?array $snapshot = null): array
    {
        $binding = $this->fixedPageBinding($menu, $page->getKey());
        if ($binding === null) {
            return ['available' => false, 'section_id' => null, 'message' => 'This Page has no developer-exposed anchors.'];
        }
        $anchorConfig = config("phi-cms.navigation.fixed_pages.{$binding['key']}.anchors.{$anchor}");
        $anchorBinding = $binding['anchors'][$anchor] ?? null;
        if (! is_array($anchorConfig) || ! is_array($anchorBinding)
            || ! is_string($anchorConfig['section_slug'] ?? null)
            || ($anchorConfig['section_slug'] !== $anchorBinding['section_slug'])
            || ($anchorConfig['slot'] ?? null) !== ($anchorBinding['slot'] ?? null)) {
            return ['available' => false, 'section_id' => null, 'message' => 'The developer anchor declaration changed.'];
        }
        $sectionId = (int) $anchorBinding['section_id'];
        $section = $snapshot['sections'][$sectionId] ?? Section::query()->find($sectionId);
        if ($section === null || $section->slug !== $anchorBinding['section_slug'] || $section->status !== SectionStatus::ACTIVE
            || ($section->content_version !== null && $section->published_content === null)) {
            return ['available' => false, 'section_id' => $sectionId, 'message' => 'The anchor Section is unavailable.'];
        }
        $membershipKey = $page->getKey() . '|' . $section->getKey();
        $assignment = $snapshot['memberships'][$membershipKey] ?? $this->publishedMembership((int) $page->getKey(), (int) $section->getKey());
        if ($assignment === null || ($page->page_version !== null && (! is_string($anchorBinding['slot'] ?? null) || $assignment->slot !== $anchorBinding['slot']))) {
            return ['available' => false, 'section_id' => (int) $section->getKey(), 'message' => 'The anchor is no longer in this Page’s public composition.'];
        }

        return ['available' => true, 'section_id' => (int) $section->getKey(), 'message' => null];
    }

    /** @return array<string, mixed>|null */
    private function fixedPageBinding(Menu $menu, int | string $pageId): ?array
    {
        foreach (($menu->adoption_bindings['fixed_pages'] ?? []) as $key => $binding) {
            if ((int) ($binding['page_id'] ?? 0) === (int) $pageId) {
                return $binding + ['key' => $key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resolution */
    private function pageChoiceLabel(array $resolution): string
    {
        $path = $resolution['href'] ?? $resolution['proposed_path'] ?? null;

        return ($resolution['title'] ?? 'Page') . ' — '
            . ($resolution['status'] === 'draft' ? 'Not published' . ($path !== null ? ' — ' . $path : '') : ($path ?? 'Unavailable'));
    }

    /** @return list<array<string, mixed>> */
    private function fixedRouteChoices(string $menuKey, Authenticatable $actor): array
    {
        $choices = [];
        $menu = $this->adoptedMenu($menuKey);
        foreach (($menu->adoption_bindings['fixed_pages'] ?? []) as $pageKey => $binding) {
            $page = Page::query()->find((int) ($binding['page_id'] ?? 0));
            if ($page === null || ! $this->mayUsePage($actor, $page, $page->status !== PageStatus::PUBLISHED)) {
                continue;
            }
            $resolution = $this->resolvePage($menu, $menuKey, (int) $page->getKey(), null, preview: true, actor: $actor);
            if ($resolution['status'] !== 'unavailable') {
                $choices[] = [
                    'destination' => ['kind' => 'page', 'page_id' => (int) $page->getKey(), 'anchor' => null],
                    'label' => $this->pageChoiceLabel($resolution),
                    'status' => $resolution['status'],
                    'href' => $resolution['href'],
                ];
            }
        }
        foreach (config('phi-cms.navigation.fixed_links', []) as $key => $definition) {
            if (! is_array($definition) || ! is_string($definition['route'] ?? null) || ! Route::has($definition['route'])) {
                continue;
            }
            $choices[] = [
                'destination' => ['kind' => 'fixed', 'key' => $key],
                'label' => $definition['label'] . ' — ' . route($definition['route'], [], false),
                'status' => 'available',
                'href' => route($definition['route'], [], false),
            ];
        }

        return $choices;
    }

    /** @return list<array<string, mixed>> */
    private function draftRows(Menu $menu): array
    {
        return DB::table('menu_items')->where('menu_id', $menu->getKey())->where('stage', 'draft')
            ->orderBy('order_column')->orderBy('id')->get()
            ->map(fn (object $row): array => $this->rowFromDatabase($row))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function publicRows(Menu $menu): array
    {
        return DB::table('menu_items')->where('menu_id', $menu->getKey())->where('stage', 'published')
            ->orderBy('order_column')->orderBy('id')->get()
            ->map(fn (object $row): array => $this->rowFromDatabase($row))
            ->all();
    }

    /** @return array<string, mixed> */
    private function rowFromDatabase(object $row): array
    {
        $destination = is_string($row->destination ?? null) ? json_decode($row->destination, true) : null;

        return [
            'id' => (int) $row->id,
            'item_key' => is_string($row->item_key ?? null) ? $row->item_key : null,
            'label' => (string) $row->label,
            'destination' => is_array($destination) ? $destination : null,
            'order_column' => (int) $row->order_column,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resolveRows(Menu $menu, string $key, array $rows, bool $preview, ?Authenticatable $actor = null): array
    {
        $snapshot = $this->referenceSnapshot($menu, $rows);
        $resolved = [];
        foreach ($rows as $row) {
            $destination = $row['destination'] ?? null;
            $resolution = is_array($destination)
                ? $this->resolveDestination($menu, $key, $destination, $preview, $actor, $snapshot)
                : ['href' => null, 'status' => 'unavailable', 'message' => 'This menu item has no supported destination.', 'title' => null, 'fingerprint' => ['missing_destination' => true]];
            $resolved[] = $row + ['resolution' => $resolution];
        }

        return $resolved;
    }

    /** @return array{href: string|null, status: string, message: string|null, title: string|null, proposed_path?: string|null, fingerprint: array<string, mixed>} */
    private function resolveDestination(Menu $menu, string $menuKey, array $destination, bool $preview, ?Authenticatable $actor, array $snapshot): array
    {
        $kind = $destination['kind'] ?? null;
        if ($kind === 'custom' && is_string($destination['url'] ?? null) && $this->isSafeCustomUrl($destination['url'])) {
            return ['href' => $destination['url'], 'status' => 'untracked', 'message' => 'Custom URL is not tracked for availability.', 'title' => null, 'fingerprint' => ['kind' => 'custom', 'url' => $destination['url']]];
        }
        if ($kind === 'fixed' && is_string($destination['key'] ?? null)) {
            $fixed = config("phi-cms.navigation.fixed_links.{$destination['key']}");
            $binding = $menu->adoption_bindings['fixed_links'][$destination['key']] ?? null;
            if (! is_array($fixed) || ! is_string($fixed['route'] ?? null)
                || ($binding['route'] ?? null) !== $fixed['route'] || ! Route::has($fixed['route'])) {
                return $this->unavailable('The declared host route is unavailable.', ['kind' => 'fixed', 'key' => $destination['key']]);
            }

            try {
                $href = route($fixed['route'], [], false);
            } catch (\Throwable) {
                return $this->unavailable('The declared host route is unavailable.', ['kind' => 'fixed', 'key' => $destination['key']]);
            }

            return ['href' => $href, 'status' => 'available', 'message' => null, 'title' => $fixed['label'] ?? null, 'fingerprint' => ['kind' => 'fixed', 'key' => $destination['key'], 'route' => $fixed['route'], 'href' => $href]];
        }
        if ($kind === 'page' && is_int($destination['page_id'] ?? null)) {
            return $this->resolvePage($menu, $menuKey, $destination['page_id'], $destination['anchor'] ?? null, $preview, $actor, $snapshot);
        }

        return $this->unavailable('This destination is no longer registered.', ['destination' => $destination]);
    }

    /** @return array{href: string|null, status: string, message: string|null, title: string|null, proposed_path?: string|null, fingerprint: array<string, mixed>} */
    private function resolvePage(Menu $menu, string $key, int $pageId, ?string $anchor, bool $preview, ?Authenticatable $actor, array $snapshot = []): array
    {
        $page = $snapshot['pages'][$pageId] ?? Page::query()->find($pageId);
        if ($page === null) {
            return $this->unavailable('The referenced Page has been removed.', ['kind' => 'page', 'page_id' => $pageId, 'anchor' => $anchor]);
        }
        if ($preview) {
            abort_if(! $actor instanceof Authenticatable, 403);
            $this->authorizePageTarget($actor, $page, $page->status !== PageStatus::PUBLISHED);
        }
        if ($page->status !== PageStatus::PUBLISHED) {
            $canReadDraft = $preview;
            $proposedPath = $canReadDraft ? $this->pageHref($menu, $page, $anchor, validateAnchor: false, snapshot: $snapshot) : null;

            return [
                'href' => null,
                'status' => 'draft',
                'message' => $canReadDraft ? 'Not published; cannot publish this menu yet.' : 'The selected Page is not publicly available.',
                'title' => $canReadDraft ? $page->draft_fields['title'] ?? $page->title : null,
                'proposed_path' => $proposedPath,
                'fingerprint' => ['kind' => 'page', 'page_id' => $pageId, 'status' => $page->status->value, 'version' => $page->page_version, 'proposed_path' => $proposedPath, 'anchor' => $anchor],
            ];
        }
        $anchorResolution = $anchor === null ? ['available' => true, 'section_id' => null, 'message' => null] : $this->anchorDefinition($menu, $page, $anchor, $snapshot);
        $href = $anchorResolution['available'] ? $this->pageHref($menu, $page, $anchor, validateAnchor: false, snapshot: $snapshot) : null;
        if ($href === null || ! $anchorResolution['available']) {
            return $this->unavailable($anchorResolution['message'] ?? 'No public route is declared for this Page.', [
                'kind' => 'page', 'page_id' => $pageId, 'slug' => $this->currentPublicSlug($page, $snapshot),
                'anchor' => $anchor, 'section_id' => $anchorResolution['section_id'],
            ], $page->title);
        }

        return [
            'href' => $href,
            'status' => 'available',
            'message' => null,
            'title' => $page->title,
            'fingerprint' => [
                'kind' => 'page',
                'page_id' => $pageId,
                'status' => $page->status->value,
                'slug' => $this->currentPublicSlug($page, $snapshot),
                'href' => $href,
                'anchor' => $anchor,
                'section_id' => $anchorResolution['section_id'],
                'section_status' => $anchorResolution['section_id'] === null
                    ? null
                    : (($snapshot['sections'][$anchorResolution['section_id']]->status ?? null)?->value ?? null),
            ],
        ];
    }

    /** @return array{href: string|null, status: string, message: string|null, title: string|null, fingerprint: array<string, mixed>} */
    private function unavailable(string $message, array $fingerprint, ?string $title = null): array
    {
        return ['href' => null, 'status' => 'unavailable', 'message' => $message, 'title' => $title, 'fingerprint' => $fingerprint];
    }

    /** @return array<string, mixed> */
    private function previewRow(array $row): array
    {
        return [
            'id' => (string) $row['item_key'],
            'item_key' => (string) $row['item_key'],
            'label' => (string) $row['label'],
            'href' => $row['resolution']['href'],
            'status' => $row['resolution']['status'],
            'message' => $row['resolution']['message'],
            'target_label' => $row['resolution']['title'],
            'proposed_path' => $row['resolution']['proposed_path'] ?? null,
            'destination' => $row['destination'],
        ];
    }

    /** @return array<string, mixed> */
    private function buildReview(Menu $menu, string $key, array $rows, Authenticatable $actor): array
    {
        $resolved = $this->resolveRows($menu, $key, $rows, preview: true, actor: $actor);
        $items = array_map(fn (array $row): array => $this->previewRow($row) + ['order_column' => $row['order_column']], $resolved);
        $published = array_map(
            fn (array $row): array => $this->previewRow($row) + ['order_column' => $row['order_column']],
            $this->resolveRows($menu, $key, $this->publicRows($menu), preview: false)
        );
        $publishable = collect($resolved)->every(fn (array $row): bool => in_array($row['resolution']['status'], ['available', 'untracked'], true));
        $fingerprint = hash('sha256', json_encode([
            'menu_id' => $menu->getKey(),
            'version' => $menu->menu_version,
            'declaration' => $this->declarationFingerprint($key),
            'rows' => array_map(fn (array $row): array => [
                'item_key' => $row['item_key'],
                'label' => $row['label'],
                'destination' => $row['destination'],
                'order_column' => $row['order_column'],
                'resolution' => $row['resolution']['fingerprint'],
            ], $resolved),
        ], JSON_THROW_ON_ERROR));

        return [
            'items' => $items,
            'changes' => $this->publicationChanges($published, $items),
            'publishable' => $publishable,
            'feedback' => array_values(array_filter(array_map(
                fn (array $row): ?array => in_array($row['resolution']['status'], ['draft', 'unavailable'], true)
                    ? ['item_key' => (string) $row['item_key'], 'message' => $row['resolution']['message']]
                    : null,
                $resolved,
            ))),
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $published
     * @param  list<array<string, mixed>>  $draft
     * @return list<array{type: string, fields: list<string>, before: array<string, mixed>|null, after: array<string, mixed>|null}>
     */
    private function publicationChanges(array $published, array $draft): array
    {
        $remaining = array_column($published, null, 'item_key');
        $changes = [];
        foreach ($draft as $item) {
            $before = $remaining[$item['item_key']] ?? null;
            unset($remaining[$item['item_key']]);
            $fields = $before === null ? [] : array_values(array_filter(
                ['label', 'destination', 'order_column', 'href'],
                static fn (string $field): bool => $before[$field] !== $item[$field],
            ));
            $changes[] = ['type' => $before === null ? 'added' : ($fields === [] ? 'unchanged' : 'changed'), 'fields' => $fields, 'before' => $before, 'after' => $item];
        }
        foreach ($remaining as $item) {
            $changes[] = ['type' => 'removed', 'fields' => [], 'before' => $item, 'after' => null];
        }

        return $changes;
    }

    private function currentPublicSlug(Page $page, array $snapshot = []): string
    {
        $snapshotSlug = $snapshot['page_urls'][$page->getKey()] ?? null;
        if (is_string($snapshotSlug) && $snapshotSlug !== '') {
            return $snapshotSlug;
        }

        if (Schema::hasTable('page_urls')) {
            $slug = PageUrl::query()->where('page_id', $page->getKey())->where('kind', 'current')->value('slug');
            if (is_string($slug) && $slug !== '') {
                return $slug;
            }
        }

        return (string) $page->slug;
    }

    /** @return array{pages: array<int, Page>, sections: array<int, Section>, page_urls: array<int, string>, memberships: array<string, object>} */
    private function referenceSnapshot(Menu $menu, array $rows): array
    {
        $pageIds = [];
        $sectionIds = [];
        foreach ($rows as $row) {
            $destination = $row['destination'] ?? null;
            if (! is_array($destination) || ($destination['kind'] ?? null) !== 'page' || ! is_int($destination['page_id'] ?? null)) {
                continue;
            }

            $pageId = $destination['page_id'];
            $pageIds[] = $pageId;
            if (! is_string($destination['anchor'] ?? null)) {
                continue;
            }

            $binding = $this->fixedPageBinding($menu, $pageId);
            $sectionId = is_array($binding)
                ? ($binding['anchors'][$destination['anchor']]['section_id'] ?? null)
                : null;
            if (is_int($sectionId)) {
                $sectionIds[] = $sectionId;
            }
        }

        $pageIds = array_values(array_unique($pageIds));
        $sectionIds = array_values(array_unique($sectionIds));
        sort($pageIds, SORT_NUMERIC);
        sort($sectionIds, SORT_NUMERIC);

        $pages = $pageIds === []
            ? []
            : Page::query()->whereIn('id', $pageIds)->get()->keyBy(fn (Page $page): int => (int) $page->getKey())->all();
        $sections = $sectionIds === []
            ? []
            : Section::query()->whereIn('id', $sectionIds)->get()->keyBy(fn (Section $section): int => (int) $section->getKey())->all();

        $pageUrls = [];
        if ($pageIds !== [] && Schema::hasTable('page_urls')) {
            $pageUrls = PageUrl::query()->whereIn('page_id', $pageIds)->where('kind', 'current')->get()
                ->mapWithKeys(fn (PageUrl $url): array => [(int) $url->page_id => (string) $url->slug])
                ->all();
        }

        $memberships = [];
        if ($pageIds !== [] && $sectionIds !== [] && Schema::hasTable('page_section')) {
            $query = DB::table('page_section')
                ->whereIn('page_id', $pageIds)
                ->whereIn('section_id', $sectionIds);
            if (Schema::hasColumn('page_section', 'stage')) {
                $query->where('stage', 'published');
            }
            $memberships = $query->get()->keyBy(fn (object $assignment): string => $assignment->page_id . '|' . $assignment->section_id)->all();
        }

        return [
            'pages' => $pages,
            'sections' => $sections,
            'page_urls' => $pageUrls,
            'memberships' => $memberships,
        ];
    }

    private function publishedMembership(int $pageId, int $sectionId): ?object
    {
        if (! Schema::hasTable('page_section')) {
            return null;
        }

        $query = DB::table('page_section')
            ->where('page_id', $pageId)
            ->where('section_id', $sectionId);
        if (Schema::hasColumn('page_section', 'stage')) {
            $query->where('stage', 'published');
        }

        return $query->first();
    }

    /** @param list<array<string, mixed>> $rows */
    private function lockReferences(array $rows, ?array $bindings = null): void
    {
        $pageIds = [];
        $sectionIds = [];
        foreach ($rows as $row) {
            $destination = $row['destination'] ?? null;
            if (! is_array($destination) || ($destination['kind'] ?? null) !== 'page' || ! is_int($destination['page_id'] ?? null)) {
                continue;
            }
            $pageIds[] = $destination['page_id'];
            $page = Page::query()->find($destination['page_id']);
            if ($page === null || ! is_string($destination['anchor'] ?? null)) {
                continue;
            }
            $fixed = $bindings === null
                ? $this->fixedPageBindingForAnyMenu($destination['page_id'])
                : $this->fixedPageBindingFromBindings($bindings, $destination['page_id']);
            if ($fixed !== null) {
                $sectionId = $fixed['anchors'][$destination['anchor']]['section_id'] ?? null;
                if (is_int($sectionId)) {
                    $sectionIds[] = $sectionId;
                }
            }
        }

        $pageIds = array_values(array_unique($pageIds));
        $sectionIds = array_values(array_unique($sectionIds));
        sort($pageIds, SORT_NUMERIC);
        sort($sectionIds, SORT_NUMERIC);

        $pages = $pageIds === []
            ? collect()
            : Page::query()->whereIn('id', $pageIds)->orderBy('id')->get();
        $pageState = $this->pageReferenceState($pageIds, $pages);
        $urlSlugs = $this->pageUrlLockSet($pageIds, $pages);
        $urlClaims = $this->pageUrlClaims($pageIds, $urlSlugs, lock: true);

        if ($sectionIds !== []) {
            Section::query()->whereIn('id', $sectionIds)->orderBy('id')->lockForUpdate()->get();
        }

        $lockedPages = $pageIds === []
            ? collect()
            : Page::query()->whereIn('id', $pageIds)->orderBy('id')->lockForUpdate()->get();
        if ($this->pageReferenceState($pageIds, $lockedPages) !== $pageState
            || $this->pageUrlClaims($pageIds, $urlSlugs) !== $urlClaims) {
            throw new MenuConflict('A Page or URL reference changed while waiting for the menu lock. Reload and retry.');
        }
    }

    /** @param list<int> $pageIds */
    private function pageReferenceState(array $pageIds, iterable $pages): array
    {
        $state = array_fill_keys($pageIds, null);
        foreach ($pages as $page) {
            $draftFields = $page->draft_fields;
            $state[(int) $page->getKey()] = [
                'page_version' => $page->page_version,
                'status' => $page->getRawOriginal('status'),
                'slug' => (string) $page->slug,
                'draft_slug' => is_array($draftFields) && is_string($draftFields['slug'] ?? null)
                    ? $draftFields['slug']
                    : null,
            ];
        }

        return $state;
    }

    /** @param list<int> $pageIds */
    private function pageUrlLockSet(array $pageIds, iterable $pages): array
    {
        $slugs = [];
        foreach ($pages as $page) {
            $slugs[] = (string) $page->slug;
            $draftFields = $page->draft_fields;
            if (is_array($draftFields) && is_string($draftFields['slug'] ?? null)) {
                $slugs[] = $draftFields['slug'];
            }
        }
        if ($pageIds !== [] && Schema::hasTable('page_urls')) {
            foreach (PageUrl::query()->whereIn('page_id', $pageIds)->pluck('slug') as $slug) {
                $slugs[] = (string) $slug;
            }
        }

        $slugs = array_values(array_unique(array_filter($slugs, static fn (string $slug): bool => $slug !== '')));
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @param  list<int>  $pageIds
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function pageUrlClaims(array $pageIds, array $slugs, bool $lock = false): array
    {
        if ($pageIds === [] || ! Schema::hasTable('page_urls')) {
            return [];
        }

        $query = PageUrl::query()->where(function (Builder $query) use ($pageIds, $slugs): void {
            $query->whereIn('page_id', $pageIds);
            if ($slugs !== []) {
                $query->orWhereIn('slug', $slugs);
            }
        });
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('slug')->orderBy('id')->get()
            ->map(fn (PageUrl $url): array => $url->getAttributes())
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function fixedPageBindingFromBindings(array $bindings, int $pageId): ?array
    {
        foreach (($bindings['fixed_pages'] ?? []) as $key => $binding) {
            if ((int) ($binding['page_id'] ?? 0) === $pageId) {
                return $binding + ['key' => $key];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function fixedPageBindingForAnyMenu(int $pageId): ?array
    {
        foreach (config('phi-cms.navigation.menus', []) as $key => $definition) {
            $menu = Menu::query()->where('key', $key)->first();
            if ($menu === null || ! is_array($menu->adoption_bindings)) {
                continue;
            }
            $binding = $this->fixedPageBinding($menu, $pageId);
            if ($binding !== null) {
                return $binding;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $expectedRows */
    private function lockMenu(Menu $menu, array $expectedRows): Menu
    {
        $fresh = Menu::query()->lockForUpdate()->findOrFail($menu->getKey());
        $actual = $this->draftRows($fresh);
        $expectedReferences = $this->referenceFingerprint($expectedRows);
        $actualReferences = $this->referenceFingerprint($actual);
        if ($actualReferences !== $expectedReferences) {
            throw new MenuConflict('The menu destination lock set changed while this request was waiting. Reload and retry.');
        }

        return $fresh;
    }

    /** @param list<array<string, mixed>> $rows */
    private function referenceFingerprint(array $rows): array
    {
        $references = [];
        foreach ($rows as $row) {
            $destination = $row['destination'] ?? null;
            if (is_array($destination) && ($destination['kind'] ?? null) === 'page') {
                $references[] = [(int) ($destination['page_id'] ?? 0), $destination['anchor'] ?? null];
            }
        }
        sort($references);

        return $references;
    }

    private function assertVersion(Menu $menu, int $expectedVersion): void
    {
        if ($menu->menu_version === null || (int) $menu->menu_version !== $expectedVersion) {
            throw new MenuConflict;
        }
    }

    private function reviewKey(Menu $menu): string
    {
        return 'phi-cms.reviews.menus.' . $menu->getKey();
    }

    private function withConsistentReadSnapshot(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction(function () use ($callback): mixed {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        });
    }

    private function transaction(\Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $exception) {
            if ($this->isConcurrencyFailure($exception)) {
                throw new MenuConflict(previous: $exception);
            }

            throw $exception;
        }
    }

    private function isConcurrencyFailure(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['40001', '40P01', '23505', '55P03'], true);
    }
}
