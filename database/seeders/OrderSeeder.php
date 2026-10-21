<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userIds = User::pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        Order::factory()
            ->count(30)
            ->state(fn () => [
                'user_id' => $userIds->random(),
            ])
            ->create();
    }
}
