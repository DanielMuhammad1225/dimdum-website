<?php

namespace App\Models;

use App\Services\LocationCatalogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug lama sebuah provinsi.
 *
 * Selalu menunjuk ke PROVINSI, bukan ke slug lain, sehingga berapa kali pun
 * slug berganti (a -> b -> c) seluruh slug lama tetap mengarah ke canonical
 * terbaru dalam satu lompatan dan rantai/loop mustahil terbentuk.
 */
class LocationProvinceSlugRedirect extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'province_id',
        'old_slug',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
