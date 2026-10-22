<section wire:key="section-{{ $section->id }}-{{ $occurrence }}" data-section-id="{{ $section->id }}" class="relative"
    @if ($preview) x-on:click="if (!$event.target.closest('button,a,input,textarea,select')) $wire.selectSection({{ $section->id }})" @endif>
    @if ($preview)
        <button type="button" wire:click="selectSection({{ $section->id }})" class="m-2 rounded border border-gray-400 bg-white px-3 py-2 text-sm text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">
            {{ __('phi-cms-core::workflow.select', ['title' => $section->title]) }}
        </button>
    @endif
    @include($definitionView, ['content' => $content])
</section>
