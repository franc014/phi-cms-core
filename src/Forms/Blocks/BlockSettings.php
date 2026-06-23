<?php

namespace JFA\PhiCmsCore\Forms\Blocks;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Str;

trait BlockSettings
{
    private static function setBlockLabel($state, $type, ?string $labelField = null)
    {
        if ($state === null) {
            return ucfirst($type);
        }

        $content = $labelField !== null
            ? ($state[$labelField] ?? '')
            : ($state['content']
                ?? $state['heading']
                ?? $state['section_title']
                ?? $state['title']
                ?? '');

        if (empty($content)) {
            return ucfirst($type);
        }

        $suffix = isset($state['items']) ? ' (' . count($state['items']) . ' items)' : '';

        return $type . ' - ' . Str::limit($content, 50, '...') . $suffix;
    }

    private static function setBlockLabelRich($state, $type)
    {
        if ($state === null) {
            return ucfirst($type);
        }

        $content = $state['content'] ?? '';

        if ($content) {
            return $type . ' - ' . Str::limit(RichContentRenderer::make($content)->toText(), 50, '...');
        }

        return '';
    }
}
