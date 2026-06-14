<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.name', 'XXXXYYYY');
        $this->migrator->add('company.email', 'jfandtec@gmail.com');
        $this->migrator->add('company.phone', '593968741465');
        $this->migrator->add('company.whatsapp', '593968741465');
        $this->migrator->add('company.address', 'Quito, Ecuador');
        $this->migrator->add('company.socialMedia', [
            'facebook' => 'https://www.facebook.com',
            'instagram' => 'https://www.instagram.com',
            'twitter' => 'https://www.twitter.com',
        ]);
        $this->migrator->add('company.workingDays', [
            'lunes' => '7 AM - 8 PM',
            'martes' => '7 AM - 8 PM',
            'miercoles' => '9 AM - 8 PM',
            'jueves' => '9 AM - 8 PM',
            'viernes' => '9 AM - 8 PM',
            'sabado' => '9 AM - 12 PM',
            'domingo' => 'no abrimos',
        ]);
    }
};
