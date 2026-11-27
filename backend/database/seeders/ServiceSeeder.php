<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['name' => 'Regular Haircut', 'price' => 35000],
            ['name' => 'Haircut + Beard Trim', 'price' => 50000],
            ['name' => 'Kids Haircut', 'price' => 25000],
            ['name' => 'Beard Trim', 'price' => 20000],
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(
                ['name' => $service['name']],
                [
                    'price' => $service['price'],
                    'is_active' => true,
                ]
            );
        }
    }
}
