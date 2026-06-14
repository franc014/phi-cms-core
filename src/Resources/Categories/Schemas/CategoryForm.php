<?php

namespace JFA\PhiCmsCore\Resources\Categories\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use JFA\PhiCmsCore\Forms\Components\SharedFields;

class CategoryForm
{
    use SharedFields;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::titleAndSlugFields(),
                Textarea::make('description')
                    ->label(__('phi-cms-core::phi-cms-core.description'))
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
