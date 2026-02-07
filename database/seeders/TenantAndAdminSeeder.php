<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create default tenant
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'default'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => null,
                'slug' => 'default',
            ]
        );

        // Initialize weights from indicator defaults (only if none exist yet)
        if ($tenant->indicatorWeights()->count() === 0) {
            $tenant->initializeWeights();
            $this->command->info("Initialized {$tenant->indicatorWeights()->count()} indicator weights for default tenant.");
        }

        // Create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        // Ensure admin is linked to the default tenant
        if ($admin->tenant_id !== $tenant->id) {
            $admin->update(['tenant_id' => $tenant->id]);
        }

        $this->command->info("Default tenant: {$tenant->uuid}");
        $this->command->info('Admin user: admin@example.com / password');
    }
}
