<?php

namespace JFA\PhiCmsCore\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PageStatus: string implements HasColor, HasLabel
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';

    public function label(): string
    {
        return match ($this) {
            self::PUBLISHED => __('phi-cms-core::phi-cms-core.published'),
            self::DRAFT => __('phi-cms-core::phi-cms-core.draft'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::PUBLISHED => 'success',
            self::DRAFT => 'warning',
        };
    }

    public function getLabel(): ?string
    {
        return $this->label();
    }
}
