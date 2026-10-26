<div class="space-y-6 bg-gray-50 p-4 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
    x-data
    x-on:phi-page-editor-focus="$nextTick(() => { const editor = $refs.pageEditor; const target = editor?.querySelector('[aria-invalid=true]') ?? editor?.querySelector('input:not([disabled]):not([type=hidden]), textarea:not([disabled])'); target?.focus(); })"
    x-on:phi-section-editor-focus="$nextTick(() => $refs.editorForm?.querySelector('input:not([disabled]):not([type=hidden]), textarea:not([disabled]), button:not([disabled])')?.focus())">
    <header>
        <h1 class="text-2xl font-semibold">{{ $pageMode ? 'Page draft preview' : __('phi-cms-core::workflow.preview') }}</h1>
        <p>{{ $pageMode ? 'This private canvas uses the saved Page draft. Public output remains unchanged until the Page is published.' : __('phi-cms-core::workflow.preview_help') }}</p>
    </header>
    <div class="grid gap-6 xl:grid-cols-3">
        @if ($page)
            <div class="min-w-0 xl:col-span-2" aria-label="{{ __('phi-cms-core::workflow.canvas') }}">
                @include($pageTemplateView, ['page' => $page, 'sections' => $sections, 'preview' => true])
            </div>
        @endif
        <aside class="min-w-0 space-y-6" aria-label="{{ __('phi-cms-core::workflow.editor') }}">
            @error('workflow') <p role="alert" class="text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
            @error('data') <p role="alert" class="text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
            @if ($pageMode)
                <section class="space-y-4 rounded border border-gray-300 p-4" aria-label="Page draft editor" x-ref="pageEditor">
                    <div>
                        <h2 class="text-xl font-semibold">Page fields</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Save Page draft and Publish Page are independent from Section publication.</p>
                    </div>
                    @error('fields') <p role="alert" class="text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    <label class="block text-sm font-medium">Title
                        <input type="text" wire:model="pageData.title" class="mt-1 block w-full rounded border-gray-300" @error('fields.title') aria-invalid="true" @enderror />
                    </label>
                    @error('fields.title') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    <label class="block text-sm font-medium">Slug
                        <input type="text" wire:model="pageData.slug" class="mt-1 block w-full rounded border-gray-300" @error('fields.slug') aria-invalid="true" @enderror />
                    </label>
                    @error('fields.slug') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    <label class="block text-sm font-medium">Description
                        <textarea wire:model="pageData.description" class="mt-1 block w-full rounded border-gray-300" @error('fields.description') aria-invalid="true" @enderror></textarea>
                    </label>
                    @error('fields.description') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    <p class="text-sm text-gray-600 dark:text-gray-300">Template (developer-controlled): <strong>{{ $pageData['template'] ?? 'Unavailable' }}</strong></p>
                    @error('fields.template') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    @if (is_array($pageData['metatags'] ?? null))
                        <details>
                            <summary class="cursor-pointer text-sm font-medium">Text SEO</summary>
                            <div class="mt-3 space-y-3">
                                @foreach (['title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_card', 'twitter_description', 'robots'] as $metaKey)
                                    <label class="block text-sm font-medium">{{ str($metaKey)->replace('_', ' ')->title() }}
                                        <input type="text" wire:model="pageData.metatags.{{ $metaKey }}" class="mt-1 block w-full rounded border-gray-300" @error('fields.metatags.'.$metaKey) aria-invalid="true" @enderror />
                                    </label>
                                    @error('fields.metatags.'.$metaKey) <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                                @endforeach
                                <p class="text-xs text-gray-500">SEO image references are preserved and read-only in this workflow.</p>
                            </div>
                        </details>
                    @else
                        <p class="text-xs text-gray-500">No SEO relationship is currently present; this workflow does not invent one.</p>
                    @endif
                    @error('fields.metatags') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                    @if ($canUpdatePage)
                        <x-filament::button wire:click="savePageDraft" wire:loading.attr="disabled">Save Page draft</x-filament::button>
                    @endif
                    <x-filament::button color="gray" wire:click="reloadPageDraft" wire:confirm="Reload the latest saved Page draft? Unsaved Page fields and the selected Section editor will be reset.">Reload Page draft</x-filament::button>
                </section>

                <section class="space-y-4 rounded border border-gray-300 p-4" aria-label="Draft sections">
                    <div>
                        <h2 class="text-xl font-semibold">Draft sections</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Reordering and additions are saved as Page draft assignments. Repeated Section identities remain separate occurrences.</p>
                    </div>
                    @foreach ($pageRows as $index => $row)
                        @php($rowSection = collect($sections)->first(fn ($section): bool => (int) $section->getKey() === (int) $row['section_id']))
                        <div wire:key="page-assignment-{{ $row['pivot_id'] ?: 'new-'.$index }}" class="flex items-center justify-between gap-2 rounded border border-gray-200 p-2">
                            <span class="text-sm">{{ $rowSection?->title ?? 'Selected Section '.$row['section_id'] }} <span class="text-gray-500">({{ $rowSection?->type ?? 'unavailable' }})</span></span>
                            @if ($row['pivot_id'])
                                <span class="flex gap-1">
                                    <x-filament::button size="sm" color="gray" wire:click="movePageSection({{ $row['pivot_id'] }}, 'up')">↑</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="movePageSection({{ $row['pivot_id'] }}, 'down')">↓</x-filament::button>
                                    <x-filament::button size="sm" color="danger" wire:click="removePageSection({{ $row['pivot_id'] }})">Remove</x-filament::button>
                                </span>
                            @endif
                        </div>
                    @endforeach
                    @if ($canComposePage && count($selectableSections) > 0)
                        <label class="block text-sm font-medium">Add existing adopted Section
                            <select wire:change="addPageSection($event.target.value)" class="mt-1 block w-full rounded border-gray-300">
                                <option value="">Choose a Section</option>
                                @foreach ($selectableSections as $selectableSection)
                                    <option value="{{ $selectableSection->id }}">{{ $selectableSection->title }} — {{ $selectableSection->type }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    @if ($canComposePage && count($restorableLegacySections) > 0)
                        <div class="space-y-2">
                            <p class="text-sm font-medium">Restore retained legacy occurrence</p>
                            @foreach ($restorableLegacySections as $legacySection)
                                <x-filament::button size="sm" color="gray" wire:click="restoreLegacyPageSection({{ $legacySection->id }})">
                                    Restore {{ $legacySection->title }} (current/live legacy)
                                </x-filament::button>
                            @endforeach
                        </div>
                    @endif
                    @if ($canComposePage)
                        <x-filament::button wire:click="savePageComposition">Save composition</x-filament::button>
                    @endif
                </section>

                @if ($canPublishPage)
                    <section class="space-y-3 rounded border border-gray-300 p-4" aria-label="Page publication">
                        <h2 class="text-xl font-semibold">Page publication</h2>
                        <p class="text-sm">Review shows the prospective public Page using currently published Section content. It never publishes a Section draft.</p>
                        <x-filament::button color="gray" wire:click="reviewPagePublication">Review Page publication</x-filament::button>
                        @if ($pageReview)
                            <div class="space-y-2 border-t border-gray-200 pt-3">
                                <p class="text-sm font-medium">{{ $pageReview['prospective_result']['title'] }} — {{ $pageReview['prospective_result']['slug'] }}</p>
                                <ul class="text-sm">
                                    @foreach ($pageReview['sections'] as $reviewSection)
                                        <li wire:key="page-review-{{ $reviewSection['occurrence_id'] }}">
                                            {{ $reviewSection['title'] }} — {{ $reviewSection['source'] }}
                                            @if (! $reviewSection['available']) <span class="text-red-700">unavailable</span> @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <iframe title="Prospective public Page — published Section content" sandbox="" width="100%" height="480" srcdoc="{{ $pageReview['prospective_result']['html'] }}"></iframe>
                                <x-filament::button wire:click="publishPage">Publish Page</x-filament::button>
                            </div>
                        @endif
                        @if ($canPublishPage && $page->status === \JFA\PhiCmsCore\Enums\PageStatus::PUBLISHED)
                            <x-filament::button color="warning" wire:click="unpublishPage" wire:confirm="Unpublish this Page? Its public URLs will stop resolving. Drafts and Section content are retained.">Unpublish Page</x-filament::button>
                        @endif
                    </section>
                @endif
                @if ($canDeletePage)
                    <section class="space-y-3 rounded border border-red-300 p-4" aria-label="Page maintenance">
                        <h2 class="text-xl font-semibold">Page maintenance</h2>
                        <p class="text-sm">Deletion writes a private recovery export first. Shared Sections and their files are retained.</p>
                        <x-filament::button color="danger" wire:click="deletePage" wire:confirm="Delete this Page? A private recovery export will be written first. Shared Sections and files are retained.">Delete Page</x-filament::button>
                    </section>
                @endif
            @endif
            @if ($pendingSectionId)
                <div role="group" aria-label="{{ __('phi-cms-core::workflow.unsaved') }}" class="space-y-3 rounded border border-gray-400 p-4">
                    <p>{{ __('phi-cms-core::workflow.switch_help') }}</p>
                    <x-filament::button wire:click="resolveSwitch('save')" wire:loading.attr="disabled">{{ __('phi-cms-core::workflow.save_switch') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="resolveSwitch('discard')">{{ __('phi-cms-core::workflow.discard') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="resolveSwitch('cancel')">{{ __('phi-cms-core::workflow.cancel') }}</x-filament::button>
                </div>
            @endif
            @if ($selectedSection)
                <h2 class="text-xl font-semibold">{{ $selectedSection->title }}</h2>
                <form wire:submit="saveDraft" x-ref="editorForm" class="space-y-4">
                    {{ $this->form }}
                    @if ($canUpdate)
                        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('phi-cms-core::workflow.save') }}</x-filament::button>
                    @endif
                    <x-filament::button color="gray" wire:click="reloadLatest" wire:confirm="{{ __('phi-cms-core::workflow.reload_confirm') }}">{{ __('phi-cms-core::workflow.reload') }}</x-filament::button>
                </form>
                @if ($canPublish)
                    <x-filament::button color="gray" wire:click="reviewPublication" wire:loading.attr="disabled">{{ __('phi-cms-core::workflow.review') }}</x-filament::button>
                @endif
                @if ($publicationReview)
                    <section class="space-y-4 border-t border-gray-300 pt-6">
                        <h3 class="font-semibold">{{ __('phi-cms-core::workflow.affected') }}</h3>
                        <ul class="space-y-2">
                            @foreach ($publicationReview['usages'] as $usage)
                                <li wire:key="usage-{{ $loop->index }}">{{ $usage['title'] }} — {{ $usage['slug'] }} — {{ $usage['status'] === 'published' ? __('phi-cms-core::workflow.live') : __('phi-cms-core::workflow.not_public') }}</li>
                            @endforeach
                        </ul>
                        <p>{{ __('phi-cms-core::workflow.review_help') }}</p>
                        <x-filament::button wire:click="publishSection" wire:loading.attr="disabled">{{ __('phi-cms-core::workflow.publish') }}</x-filament::button>
                    </section>
                @endif
            @else
                <p>{{ __('phi-cms-core::workflow.select_help') }}</p>
            @endif
        </aside>
    </div>
</div>
