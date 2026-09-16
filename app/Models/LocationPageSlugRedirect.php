<?php

namespace App\Models;

use App\Services\LocationPageCatalogService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slug lama sebuah Halaman Slug Lokasi.
 *
 * Selalu menunjuk ke HALAMAN, bukan ke slug lain, sehingga berapa kali pun
 * slug berganti (a -> b -> c) seluruh slug lama tetap mengarah ke canonical
 * terbaru dalam satu lompatan dan rantai/loop mustahil terbentuk.
 */
class LocationPageSlugRedirect extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_page_id',
        'old_slug',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => LocationPageCatalogService::flushCache());
        static::deleted(fn () => LocationPageCatalogService::flushCache());
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LocationPage::class, 'location_page_id');
    }
}
