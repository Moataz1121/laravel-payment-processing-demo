<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->words(3, true)),
            'price' => $this->faker->randomFloat(2, 5, 999),
            'currency' => 'USD',
            'quantity' => $this->faker->numberBetween(10, 200),
            'is_active' => true,
        ];
    }
}
