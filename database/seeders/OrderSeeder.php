<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
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
        $productIds = Product::pluck('id');

        if ($userIds->isEmpty() || $productIds->isEmpty()) {
            return;
        }

        Order::factory()
            ->count(30)
            ->state(fn () => [
                'user_id' => $userIds->random(),
                'product_id' => $productIds->random(),
            ])
            ->create();
    }
}
