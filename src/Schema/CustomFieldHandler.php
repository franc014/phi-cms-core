<?php

namespace JFA\PhiCmsCore\Schema;

use Filament\Schemas\Components\Component;
use JFA\PhiCmsCore\Schema\Fields\CustomField;

interface CustomFieldHandler
{
    public function handle(CustomField $field): Component;
}
