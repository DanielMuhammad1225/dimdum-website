<?php

namespace Database\Factories;

use App\Enums\SocialIcon;
use App\Models\SocialLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory HANYA untuk test. Tidak dipakai seeder mana pun.
 *
 * Namanya memakai penanda "Tautan Uji" dan domain .test supaya baris yang
 * lolos ke database development mudah dikenali dan tidak pernah menaut ke
 * akun sungguhan.
 *
 * @extends Factory<SocialLink>
 */
class SocialLinkFactory extends Factory
{
    protected $model = SocialLink::class;

    public function definition(): array
    {
        return [
            'name' => 'Tautan Uji '.Str::upper(Str::random(4)),
            'url' => 'https://tautan-uji.test/'.Str::lower(Str::random(8)),
            'icon' => SocialIcon::Link->value,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
