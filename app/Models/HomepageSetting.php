<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton konten homepage.
 *
 * Setiap kolom section berisi array asosiatif dengan bentuk yang sama persis
 * dengan config/homepage.php, sehingga Blade tidak perlu diubah.
 */
class HomepageSetting extends Model
{
    public const SINGLETON_KEY = 'homepage';

    public const CACHE_KEY = 'homepage_settings.homepage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'hero',
        'usp',
        'products',
        'how',
        'budget',
        'locations',
        'cta',
        'sections',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hero' => 'array',
            'usp' => 'array',
            'products' => 'array',
            'how' => 'array',
            'budget' => 'array',
            'locations' => 'array',
            'cta' => 'array',
            'sections' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
