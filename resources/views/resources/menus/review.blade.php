@if (is_array($review))
    <div class="space-y-4">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Compare the published menu with the saved draft below. Publishing changes only this menu.
        </p>

        <h3>Published → Saved draft</h3>
        <ol>
            @foreach ($review['changes'] as $change)
                <li wire:key="menu-change-{{ $change['after']['id'] ?? $change['before']['id'] }}">
                    <strong>{{ ucfirst($change['type']) }}</strong>
                    @if ($change['fields'] !== [])
                        <span>({{ implode(', ', $change['fields']) }})</span>
                    @endif
                    <p>Published:
                        @if ($change['before'] !== null)
                            #{{ $change['before']['order_column'] }} {{ $change['before']['label'] }} — {{ $change['before']['href'] ?? 'Unavailable' }}
                            ({{ json_encode($change['before']['destination'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }})
                        @else
                            Not in published menu
                        @endif
                    </p>
                    <p>Saved draft:
                        @if ($change['after'] !== null)
                            #{{ $change['after']['order_column'] }} {{ $change['after']['label'] }} — {{ $change['after']['href'] ?? 'Unavailable' }}
                            ({{ json_encode($change['after']['destination'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }})
                        @else
                            Removed from menu
                        @endif
                    </p>
                </li>
            @endforeach
        </ol>

        <ol class="space-y-2">
            @forelse ($review['items'] as $item)
                <li wire:key="menu-review-{{ $item['id'] }}" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
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
                <li class="text-sm text-gray-600 dark:text-gray-300">Publishing will intentionally leave this menu empty.</li>
            @endforelse
        </ol>

        @if ($review['feedback'] !== [])
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                <h3 class="font-semibold">Publication is blocked</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($review['feedback'] as $feedback)
                        <li>{{ $feedback['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
