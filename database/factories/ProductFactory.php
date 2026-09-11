<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test. Tidak dipakai seeder mana pun.
 *
 * Namanya memakai penanda "Produk Uji" supaya baris yang lolos ke database
 * development mudah dikenali. Produk asli dibuat pemilik project lewat admin.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => 'Produk Uji '.Str::upper(Str::random(5)),
            'category' => ProductCategory::Menu->value,
            'type' => ProductType::Satuan->value,
            'description' => null,
            // Tanpa harga secara bawaan: kartu tanpa label harga adalah
            // keadaan yang harus tetap benar, jadi ia yang menjadi default.
            'price' => null,
            'price_text' => null,
            'image_path' => null,
            'image_alt' => null,
            'emoji' => null,
            'is_active' => true,
            'show_on_homepage' => false,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function onHomepage(): static
    {
        return $this->state(fn (): array => ['show_on_homepage' => true]);
    }

    public function onBio(): static
    {
        return $this->state(fn (): array => ['show_on_bio' => true]);
    }

    public function withNumericPrice(int $price = 5000): static
    {
        return $this->state(fn (): array => ['price' => $price, 'price_text' => null]);
    }

    public function withPriceText(string $text = 'Rp5.000/pcs'): static
    {
        return $this->state(fn (): array => ['price_text' => $text]);
    }

    public function withEmoji(string $emoji = '🥟'): static
    {
        return $this->state(fn (): array => ['emoji' => $emoji]);
    }

    public function category(ProductCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category->value]);
    }

    public function type(ProductType $type): static
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }
}
