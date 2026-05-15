<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Populate the queue-insights dashboard with a small mix of
        // jobs (immediate / delayed / batched / failing) on first seed
        // so the demo isn't empty after `php artisan migrate:fresh
        // --seed`. Each dispatch carries `Illuminate\Support\Facades\
        // Context` keys (request id, user id, payment id, …) — when
        // `QUEUE_INSIGHTS_PENDING_CAPTURE_PAYLOADS=full` the dashboard
        // decodes the `illuminate:log:context` entry inline via
        // ValueParser. Run `php artisan demo:spray-jobs --count=N` to
        // top it up later.
        Artisan::call('demo:spray-jobs', ['--count' => 4]);
    }
}
