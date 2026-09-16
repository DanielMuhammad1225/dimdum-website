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
 * Area -- tingkat ketiga hierarki lokasi, dan induk langsung gerobak.
 *
 * MASTER DATA MURNI: tanpa slug, tanpa halaman, tanpa SEO.
 *
 * Area adalah satuan operasional/pemasaran, bukan selalu kecamatan. Ia berada
 * di bawah satu Kota/Grup, dan provinsinya diturunkan lewat rantai
 * Area -> LocationGroup -> Province -- tidak pernah disimpan ulang di sini.
 *
 * PENTING: Area BUKAN input Halaman Slug Lokasi. Halaman memilih Kota/Grup;
 * Area hanya menjadi konteks pengelompokan saat menampilkan kandidat gerobak.
 */
class LocationArea extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_group_id',
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
        static::saved(fn () => LocationPageCatalogService::flushCache());
        static::deleted(fn () => LocationPageCatalogService::flushCache());
        static::restored(fn () => LocationPageCatalogService::flushCache());
        static::forceDeleted(fn () => LocationPageCatalogService::flushCache());
    }

    // --------------------------------------------------------- relationships

    public function group(): BelongsTo
    {
        return $this->belongsTo(LocationGroup::class, 'location_group_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Foto seluruh gerobak di area ini.
     */
    public function images(): HasManyThrough
    {
        return $this->hasManyThrough(LocationImage::class, Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Provinsi induk, diturunkan lewat Kota/Grup.
     *
     * Sengaja BUKAN relationship: memanggilnya tanpa eager load
     * `group.province` akan memicu lazy load, dan di environment testing
     * Model::preventLazyLoading() menjadikannya exception -- sehingga N+1
     * ketahuan di test, bukan di production.
     */
    public function parentProvince(): ?Province
    {
        return $this->group?->province;
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Visibilitas EFEKTIF: area baru menyumbang gerobak bila Kota/Grup-nya
     * aktif DAN provinsinya aktif.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereHas('group', fn (Builder $group) => $group->effectivelyVisible());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    // ------------------------------------------------------------ visibility

    public function isActiveArea(): bool
    {
        return ! $this->trashed() && (bool) $this->is_active;
    }

    /**
     * Termasuk seluruh rantai induk: Kota/Grup aktif dan provinsi aktif.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isActiveArea()) {
            return false;
        }

        return $this->group?->isEffectivelyVisible() ?? false;
    }
}
