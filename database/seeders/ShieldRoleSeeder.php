<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class ShieldRoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::findOrCreate('super_admin');
        $customerRole = Role::findOrCreate('customer');

        User::query()
            ->whereDoesntHave('roles')
            ->get()
            ->each(function (User $user) use ($customerRole, $superAdminRole): void {
                if ($user->hasRole($superAdminRole->name)) {
                    return;
                }

                $user->assignRole($customerRole);
            });
    }
}
