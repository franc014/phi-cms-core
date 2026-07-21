<?php

declare(strict_types=1);

namespace JFA\PhiCmsCore\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use JFA\PhiCmsCore\Database\Seeders\ShieldRoleSeeder;
use JFA\PhiCmsCore\Database\Seeders\ShieldUserSeeder;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'phi-cms:seed')]
class SeedCommand extends Command
{
    protected $signature = 'phi-cms:seed
        {--without-users : Seed roles only, skip creating users}
        {--only= : Seed only roles or users (roles|users)}';

    protected $description = 'Seed Shield roles, permissions, and default users';

    public function handle(): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['roles', 'users'], true)) {
            $this->error('The --only option accepts "roles" or "users".');

            return self::FAILURE;
        }

        $seedRoles = $only === null || $only === 'roles';
        $seedUsers = ($only === null && ! $this->option('without-users')) || $only === 'users';

        $this->publishSeedConfig();

        if ($seedRoles) {
            $this->info('Seeding Shield roles and permissions…');
            app(ShieldRoleSeeder::class)->run();
        }

        if ($seedUsers) {
            $this->info('Seeding default users…');
            app(ShieldUserSeeder::class)->run();
        }

        $this->newLine();
        $this->info('Shield seeded successfully.');

        return self::SUCCESS;
    }

    private function publishSeedConfig(): void
    {
        Artisan::call('vendor:publish', [
            '--tag' => 'phi-cms-seed-config',
            '--force' => true,
        ]);
    }
}
