<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Wireless Headphones', 'price' => 79.99, 'stock' => 50],
            ['name' => 'Mechanical Keyboard', 'price' => 129.99, 'stock' => 30],
            ['name' => 'USB-C Hub', 'price' => 39.99, 'stock' => 100],
            ['name' => 'Webcam HD 1080p', 'price' => 59.99, 'stock' => 40],
            ['name' => 'Desk Lamp LED', 'price' => 24.99, 'stock' => 75],
        ];

        foreach ($products as $product) {
            DB::table('products')->insert([
                'id' => Str::uuid(),
                'name' => $product['name'],
                'price' => $product['price'],
                'stock' => $product['stock'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->info('Seeded 5 sample products.');
    }
}