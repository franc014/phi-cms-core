<?php

namespace JFA\PhiCmsCore\Resources\Menus\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use JFA\PhiCmsCore\Forms\Components\SharedFields;
use JFA\PhiCmsCore\Models\Menu;

class MenuForm
{
    use SharedFields;

    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        if ($record instanceof Menu && $record->menu_version !== null) {
            return $schema
                ->components([
                    Section::make($record->title)
                        ->description('This developer-declared menu location is managed as a flat list. Its public links are changed only through the reviewed menu workflow.')
                        ->schema([
                            Placeholder::make('key')->label('Menu key')->content($record->key),
                            Placeholder::make('menu_version')->label('Saved menu version')->content((string) $record->menu_version),
                        ]),
                ]);
        }

        return $schema
            ->components([
                ...self::titleAndSlugFields(),
                Textarea::make('description')
                    ->label(__('phi-cms-core::phi-cms-core.description'))
                    ->required()
                    ->maxLength(140),
            ]);
    }
}
