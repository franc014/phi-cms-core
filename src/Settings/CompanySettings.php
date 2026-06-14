<?php

namespace JFA\PhiCmsCore\Settings;

use Spatie\LaravelSettings\Settings;

class CompanySettings extends Settings
{
    public string $name;

    public string $email;

    public string $phone;

    public string $whatsapp;

    public string $address;

    public array $socialMedia;

    public array $workingDays;

    public static function group(): string
    {
        return 'company';
    }
}
