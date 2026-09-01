<?php

namespace App\Models;

use App\Services\LocationPageCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Provinsi -- tingkat teratas hierarki lokasi.
 *
 * MASTER DATA MURNI. Provinsi tidak punya slug, halaman, maupun SEO sendiri:
 * seluruh URL publik dimiliki App\Models\LocationPage. Yang tersisa di sini
 * hanyalah identitas, status operasional, urutan, dan audit.
 *
 * `is_active` kini berarti "sedang dipakai", bukan "terbit". Provinsi nonaktif
 * memutus rantai visibilitas seluruh Kota/Grup, Area, dan gerobak di bawahnya,
 * sehingga gerobaknya berhenti tampil di halaman slug mana pun.
 */
class Province extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         | Fakta provinsi ikut tercetak di payload halaman slug, jadi setiap
         | perubahan di sini membuat cache halaman basi.
         */
        static::saved(fn () => LocationPageCatalogService::flushCache());
        static::deleted(fn () => LocationPageCatalogService::flushCache());
        static::restored(fn () => LocationPageCatalogService::flushCache());
        static::forceDeleted(fn () => LocationPageCatalogService::flushCache());
    }

    // --------------------------------------------------------- relationships

    public function groups(): HasMany
    {
        return $this->hasMany(LocationGroup::class);
    }

    /**
     * Seluruh Area di provinsi ini, menembus Kota/Grup.
     */
    public function areas(): HasManyThrough
    {
        return $this->hasManyThrough(LocationArea::class, LocationGroup::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ---------------------------------------------------------------- scopes

    /*
     | Seluruh scope memakai kolom BERKUALIFIKASI (nama tabel disertakan).
     | provinces, location_groups, location_areas, dan locations sama-sama
     | punya kolom is_active dan sort_order, sehingga scope tanpa kualifikasi
     | menjadi ambigu begitu ikut dalam JOIN atau whereHas bertingkat.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    // ------------------------------------------------------------ visibility

    /**
     * Provinsi ini boleh menyumbang gerobak ke halaman publik?
     */
    public function isEffectivelyVisible(): bool
    {
        return ! $this->trashed() && (bool) $this->is_active;
    }
}
