<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Seeder;

class OrderItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Product::count() === 0) {
            return;
        }

        Order::all()->each(function (Order $order) {
            $items = OrderItem::factory()
                ->count(fake()->numberBetween(1, 4))
                ->create(['order_id' => $order->id]);

            $order->update(['total_amount' => $items->sum('line_total')]);
        });
    }
}
