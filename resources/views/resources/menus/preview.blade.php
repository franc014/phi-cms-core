<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        This is the saved menu draft. Page and Section drafts are not included.
    </p>

    <ol class="space-y-2">
        @forelse ($preview['items'] as $item)
            <li wire:key="menu-preview-{{ $item['id'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ $item['label'] }}</span>
                    <span class="text-xs font-semibold uppercase tracking-wide">{{ $item['status'] }}</span>
                </div>

                @if (is_string($item['href']))
                    <p class="mt-1 break-all text-sm text-gray-600 dark:text-gray-300">{{ $item['href'] }}</p>
                @endif

                @if (filled($item['message']))
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ $item['message'] }}</p>
                @endif
            </li>
        @empty
            <li class="text-sm text-gray-600 dark:text-gray-300">The saved draft intentionally contains no links.</li>
        @endforelse
    </ol>

    @if ($preview['feedback'] !== [])
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <h3 class="font-semibold">Menu items need attention</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($preview['feedback'] as $feedback)
                    <li>{{ $feedback['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
