<?php

namespace JFA\PhiCmsCore\Pages;

use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use JFA\PhiCmsCore\Settings\CompanySettings;
use UnitEnum;

class ManageCompany extends SettingsPage
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = CompanySettings::class;

    public static function getNavigationGroup(): UnitEnum | string | null
    {
        return __('phi-cms-core::phi-cms-core.settings');
    }

    public function getTitle(): string
    {
        return __('phi-cms-core::phi-cms-core.company_settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('phi-cms-core::phi-cms-core.company_settings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('phi-cms-core::phi-cms-core.title'))
                    ->required(),
                TextInput::make('email')
                    ->email()
                    ->required(),
                TextInput::make('phone')
                    ->label(__('phi-cms-core::phi-cms-core.phone'))
                    ->tel()
                    ->required(),
                TextInput::make('whatsapp')
                    ->required(),
                TextInput::make('address')
                    ->label(__('phi-cms-core::phi-cms-core.address'))
                    ->columnSpanFull()
                    ->required(),
                KeyValue::make('socialMedia')
                    ->label(__('phi-cms-core::phi-cms-core.social_media'))
                    ->keyLabel(__('phi-cms-core::phi-cms-core.title'))
                    ->valueLabel(__('phi-cms-core::phi-cms-core.link'))
                    ->keyPlaceholder(__('phi-cms-core::phi-cms-core.social_media_name'))
                    ->valuePlaceholder(__('phi-cms-core::phi-cms-core.social_media_link')),

                KeyValue::make('workingDays')
                    ->label(__('phi-cms-core::phi-cms-core.working_days'))
                    ->keyLabel(__('phi-cms-core::phi-cms-core.day'))
                    ->valueLabel(__('phi-cms-core::phi-cms-core.hours'))
                    ->keyPlaceholder(__('phi-cms-core::phi-cms-core.monday'))
                    ->valuePlaceholder('09:00 - 8:00'),
            ]);
    }
}
