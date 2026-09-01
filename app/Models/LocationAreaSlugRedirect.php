<?php

namespace App\Models;

use App\Services\LocationCatalogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug lama sebuah wilayah landing.
 *
 * Selalu menunjuk ke AREA, bukan ke slug lain, sehingga redirect tidak
 * mungkin membentuk rantai atau loop: berapa kali pun slug berganti, seluruh
 * slug lama tetap mengarah ke satu canonical terbaru milik area tersebut.
 */
class LocationAreaSlugRedirect extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_area_id',
        'old_slug',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(LocationArea::class, 'location_area_id');
    }
}
