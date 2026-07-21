<?php

use Illuminate\Support\Facades\Artisan;
use JFA\PhiCmsCore\Commands\SeedCommand;
use JFA\PhiCmsCore\Database\Seeders\ShieldRoleSeeder;
use JFA\PhiCmsCore\Database\Seeders\ShieldUserSeeder;

beforeEach(function (): void {
    $this->roleSeederRunCount = 0;
    $this->userSeederRunCount = 0;

    app()->instance(ShieldRoleSeeder::class, new class($this) extends ShieldRoleSeeder
    {
        public function __construct(private $test) {}

        public function run(): void
        {
            $this->test->roleSeederRunCount++;
        }
    });

    app()->instance(ShieldUserSeeder::class, new class($this) extends ShieldUserSeeder
    {
        public function __construct(private $test) {}

        public function run(): void
        {
            $this->test->userSeederRunCount++;
        }
    });
});

it('runs both seeders by default', function (): void {
    Artisan::call('phi-cms:seed');

    expect($this->roleSeederRunCount)->toBe(1)
        ->and($this->userSeederRunCount)->toBe(1);
});

it('skips user seeder with --without-users', function (): void {
    Artisan::call('phi-cms:seed', ['--without-users' => true]);

    expect($this->roleSeederRunCount)->toBe(1)
        ->and($this->userSeederRunCount)->toBe(0);
});

it('runs only the role seeder with --only=roles', function (): void {
    Artisan::call('phi-cms:seed', ['--only' => 'roles']);

    expect($this->roleSeederRunCount)->toBe(1)
        ->and($this->userSeederRunCount)->toBe(0);
});

it('runs only the user seeder with --only=users', function (): void {
    Artisan::call('phi-cms:seed', ['--only' => 'users']);

    expect($this->roleSeederRunCount)->toBe(0)
        ->and($this->userSeederRunCount)->toBe(1);
});

it('rejects invalid --only value', function (): void {
    $exitCode = Artisan::call('phi-cms:seed', ['--only' => 'invalid']);

    expect($exitCode)->toBe(1);
});

it('describes the command', function (): void {
    expect(app(SeedCommand::class)->getDescription())
        ->toBe('Seed Shield roles, permissions, and default users');
});
