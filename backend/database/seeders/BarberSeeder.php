<?php

namespace Database\Seeders;

use App\Models\Barber;
use Illuminate\Database\Seeder;

class BarberSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Budi', 'Raka', 'Dimas'] as $name) {
            Barber::updateOrCreate(
                ['name' => $name],
                [
                    'is_active' => true,
                    'current_status' => 'idle',
                ]
            );
        }
    }
}
