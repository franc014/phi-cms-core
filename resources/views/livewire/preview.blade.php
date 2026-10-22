<div class="space-y-6 bg-gray-50 p-4 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('phi-cms-core::workflow.preview') }}</h1>
        <p>{{ __('phi-cms-core::workflow.preview_help') }}</p>
    </header>
    <div class="grid gap-6 xl:grid-cols-3">
        @if ($page)
            <div class="min-w-0 xl:col-span-2" aria-label="{{ __('phi-cms-core::workflow.canvas') }}">
                @include($pageView, ['page' => $page, 'sections' => $sections, 'preview' => true])
            </div>
        @endif
        <aside class="min-w-0 space-y-6" aria-label="{{ __('phi-cms-core::workflow.editor') }}">
            @error('workflow') <p role="alert" class="text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
            @error('data') <p role="alert" class="text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
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
                <form wire:submit="saveDraft" class="space-y-4">
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
