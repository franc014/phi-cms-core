<?php

namespace JFA\PhiCmsCore\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SectionStatus: string implements HasColor, HasLabel
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('phi-cms-core::phi-cms-core.active'),
            self::INACTIVE => __('phi-cms-core::phi-cms-core.inactive'),
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::INACTIVE => 'warning',
            self::ACTIVE => 'success',
        };
    }

    public function getLabel(): ?string
    {
        return $this->label();
    }
}
