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
        $confirmedOrderIds = Order::where('status', 'confirmed')->pluck('id');

        if ($confirmedOrderIds->isEmpty()) {
            return;
        }

        Payment::factory()
            ->count($confirmedOrderIds->count())
            ->sequence(fn ($sequence) => ['order_id' => $confirmedOrderIds[$sequence->index]])
            ->create();
    }
}
