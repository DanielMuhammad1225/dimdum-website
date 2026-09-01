<?php

namespace App\Models;

use App\Enums\LocationGroupType;
use App\Services\LocationCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kota/Grup -- tingkat kedua hierarki lokasi.
 *
 * TIDAK punya halaman publik sendiri: ia hanya menjadi heading pengelompokan
 * di halaman Provinsi. Karena itu ia tidak punya published_at maupun riwayat
 * slug -- tidak ada URL yang bisa mati ketika slug-nya berganti.
 *
 * Visibilitasnya cukup `aktif dan tidak terhapus`, tetapi tetap ikut memutus
 * rantai: grup nonaktif menyembunyikan seluruh Area dan gerobak di bawahnya.
 */
class LocationGroup extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * slug tidak fillable: penggantiannya melewati service yang memeriksa
     * keunikan di dalam provinsi.
     *
     * @var list<string>
     */
    protected $fillable = [
        'province_id',
        'name',
        'type',
        'description',
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
            'type' => LocationGroupType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
        static::restored(fn () => LocationCatalogService::flushCache());
        static::forceDeleted(fn () => LocationCatalogService::flushCache());
    }

    // --------------------------------------------------------- relationships

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(LocationArea::class);
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Grup yang benar-benar tampil: aktif DAN provinsinya tampil.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereHas('province', fn (Builder $province) => $province->publiclyVisible());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    public function scopeHasVisibleAreas(Builder $query): Builder
    {
        return $query->whereHas(
            'areas',
            fn (Builder $areas) => $areas
                ->publiclyVisible()
                ->whereHas('locations', fn (Builder $locations) => $locations->publiclyVisible())
        );
    }

    // ------------------------------------------------------------ visibility

    /**
     * Kota/Grup tidak punya published_at -- aktif sudah cukup.
     */
    public function isActiveGroup(): bool
    {
        return ! $this->trashed() && (bool) $this->is_active;
    }

    /**
     * Termasuk memeriksa provinsi induk. Memakai relasi yang sudah dimuat
     * bila tersedia supaya tidak memicu query tambahan.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isActiveGroup()) {
            return false;
        }

        return $this->province?->isPubliclyVisible() ?? false;
    }

    // ------------------------------------------------------------- accessors

    public function typeLabel(): string
    {
        return $this->type instanceof LocationGroupType
            ? $this->type->label()
            : '-';
    }
}
