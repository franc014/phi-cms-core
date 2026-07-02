<?php

declare(strict_types=1);

namespace JFA\PhiCmsCore\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ShieldUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the user seeds for Shield roles.
     *
     * Reads user definitions from config('phi-cms-seed.users'). Each key
     * is a role name; set a user entry to null to skip creating that user.
     */
    public function run(): void
    {
        Model::unguard();

        $password = Hash::make(config('phi-cms-seed.password', 'password'));

        $users = config('phi-cms-seed.users', []);

        foreach ($users as $roleName => $userData) {
            if ($userData === null) {
                continue;
            }

            $this->createUser(
                name: $userData['name'],
                email: $userData['email'],
                password: $password,
                roleName: $roleName,
            );
        }

        Model::reguard();
    }

    private function createUser(string $name, string $email, string $password, string $roleName): void
    {
        $role = Role::where('name', $roleName)->first();

        $userClass = config('filament-shield.auth_provider_model', 'App\\Models\\User');

        $user = $userClass::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
            ],
        );

        if ($role && ! $user->hasRole($roleName)) {
            $user->assignRole($role);
        }
    }
}
