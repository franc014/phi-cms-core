<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default User Password
    |--------------------------------------------------------------------------
    |
    | The default password used for all seeded users. In production, you
    | should change this before seeding or use environment variables.
    |
    */

    'password' => env('PHI_CMS_SEED_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | User Definitions
    |--------------------------------------------------------------------------
    |
    | Define the users to create during seeding. Each entry maps a Shield
    | role to a user record. The password is resolved from the 'password'
    | key above unless overridden per-user.
    |
    | To disable creating a specific user, set its value to null.
    |
    */

    'users' => [
        'super_admin' => [
            'name' => 'Super Admin',
            'email' => 'superadmin@test.com',
        ],
        'admin' => [
            'name' => 'Admin User',
            'email' => 'admin@test.com',
        ],
        'frontend_editor' => [
            'name' => 'Frontend Editor',
            'email' => 'editor@test.com',
        ],
        'panel_user' => [
            'name' => 'Panel User',
            'email' => 'paneluser@test.com',
        ],
    ],

];
