<?php

namespace JFA\PhiCmsCore\Enums;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup: string implements HasLabel
{
    case BUSINESS_CONTENT = 'business_content';
    case CMS = 'cms';
    case BASIC_INFO = 'basic_info';
    case BLOG = 'blog';

    public function getLabel(): string
    {
        return match ($this) {
            self::BUSINESS_CONTENT => __('phi-cms-core::phi-cms-core.business_content'),
            self::CMS => __('phi-cms-core::phi-cms-core.cms'),
            self::BASIC_INFO => __('phi-cms-core::phi-cms-core.basic_info'),
            self::BLOG => __('phi-cms-core::phi-cms-core.blog'),
        };
    }
}
