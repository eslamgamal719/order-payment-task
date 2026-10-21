<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $confirmedOrders = Order::where('status', 'confirmed')->get();

        if ($confirmedOrders->isEmpty()) {
            return;
        }

        $confirmedOrders->each(function (Order $order) {
            Payment::factory()->create([
                'order_id' => $order->id,
                'amount' => $order->total_amount,
            ]);
        });
    }
}
