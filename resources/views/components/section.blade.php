<section wire:key="section-{{ $section->id }}-{{ $occurrence }}" data-section-id="{{ $section->id }}" class="relative"
    @if ($preview && $canSelect) x-on:click="if (!$event.target.closest('button,a,input,textarea,select')) $wire.selectSection({{ $section->id }})" @endif>
    @if ($preview && $canSelect)
        <button type="button" wire:click="selectSection({{ $section->id }})" class="m-2 rounded border border-gray-400 bg-white px-3 py-2 text-sm text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            {{ __('phi-cms-core::workflow.select', ['title' => $section->title]) }}
        </button>
    @endif
    @if ($preview && $source === 'published')
        <p role="status" class="mx-2 mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">
            Published Section content — this draft is unavailable to your account.
        </p>
    @endif
    @if ($unavailable)
        <div role="status" class="m-2 rounded border border-gray-300 bg-white px-3 py-4 text-sm text-gray-700">
            Unavailable Section content — this draft and its published version cannot be displayed.
        </div>
    @else
        @include($definitionView, ['content' => $content, 'images' => $images])
    @endif
</section>
