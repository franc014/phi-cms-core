@if ($manageSectionsUrl)
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <x-filament::button tag="a" href="{{ $manageSectionsUrl }}" color="gray">
                {{ __('phi-cms-core::workflow.draft_sections') }}
            </x-filament::button>
            @if ($createSectionUrl)
                <x-filament::button tag="a" href="{{ $createSectionUrl }}" color="gray" data-create-services>
                    {{ __('phi-cms-core::workflow.create_and_add_services') }}
                </x-filament::button>
            @endif
            @if ($copySectionUrl)
                <x-filament::button tag="a" href="{{ $copySectionUrl }}" color="gray" data-copy-services>
                    {{ __('phi-cms-core::workflow.copy_published_services') }}
                </x-filament::button>
            @endif
        </div>
        @if ($createSectionUrl)
            <p class="text-sm">{{ __('phi-cms-core::workflow.independent_services_help') }}</p>
        @endif
    </div>
@endif
