<?php

declare(strict_types=1);

namespace JFA\PhiCmsCore\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ShieldRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the role and permission seeds for Shield.
     */
    public function run(): void
    {
        $allPermissions = $this->ensurePermissions($this->allPermissionNames());

        $superAdmin = $this->createRole('super_admin');
        $superAdmin->givePermissionTo($allPermissions);

        $admin = $this->createRole('admin');
        $admin->givePermissionTo($this->ensurePermissions($this->adminPermissions()));

        $frontendEditor = $this->createRole('frontend_editor');
        $frontendEditor->givePermissionTo($this->ensurePermissions($this->frontendEditorPermissions()));

        $this->createRole('panel_user');
    }

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Permission>
     */
    private function ensurePermissions(array $names): Collection
    {
        return collect($names)->map(fn (string $name): Permission => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));
    }

    private function createRole(string $name): Role
    {
        return Role::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function allPermissionNames(): array
    {
        return array_unique(array_merge(
            $this->adminPermissions(),
            $this->frontendEditorPermissions(),
            $this->workflowPermissions(),
        ));
    }

    /**
     * Page and Section workflow authority stays independent from the CRUD grants.
     *
     * @return array<int, string>
     */
    private function workflowPermissions(): array
    {
        return [
            'Preview:Page',
            'Compose:Page',
            'Publish:Page',
            'ViewDraft:Section',
            'Use:Section',
            'Publish:Section',
            'Replicate:Section',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function adminPermissions(): array
    {
        return [
            'ViewAny:Page', 'View:Page', 'Create:Page', 'Update:Page', 'Delete:Page', 'DeleteAny:Page',
            'ViewAny:Section', 'View:Section', 'Create:Section', 'Update:Section', 'Delete:Section', 'DeleteAny:Section',
            'ViewAny:Post', 'View:Post', 'Create:Post', 'Update:Post', 'Delete:Post', 'DeleteAny:Post',
            'ViewAny:Category', 'View:Category', 'Create:Category', 'Update:Category', 'Delete:Category', 'DeleteAny:Category',
            'ViewAny:Menu', 'View:Menu', 'Create:Menu', 'Update:Menu', 'Delete:Menu', 'DeleteAny:Menu',
            'ViewAny:Role', 'View:Role', 'Create:Role', 'Update:Role', 'Delete:Role', 'DeleteAny:Role',
            'View:ManageCompany',
            'EditFrontendContent',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function frontendEditorPermissions(): array
    {
        return [
            'ViewAny:Page', 'View:Page',
            'ViewAny:Section', 'View:Section', 'Update:Section',
            'View:ManageCompany',
            'EditFrontendContent',
        ];
    }
}
