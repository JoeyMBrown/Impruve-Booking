<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class UserSeeder extends Seeder
{
    /**
     * Seed the placeholder users. Runs on a fresh install only.
     */
    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command->warn('Users already exist; skipping. Seeders run on a fresh install only.');

            return;
        }

        /** @var list<array{name: string, email?: string|null}> $users */
        $users = File::json(database_path('seeders/data/users.json'), JSON_THROW_ON_ERROR);

        foreach ($users as $user) {
            User::create([
                'name' => $user['name'],
                'email' => $user['email'] ?? null,
            ]);
        }
    }
}
