<?php

namespace App\Models;

use App\Services\LocationPageCatalogService;
use App\Support\MapsUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Gerobak DIMDUM -- titik fisik, tingkat terbawah hierarki.
 *
 * MASTER DATA. Gerobak tidak punya slug maupun halaman sendiri; ia TAMPIL di
 * Halaman Slug Lokasi yang memilihnya secara eksplisit.
 *
 * Induknya adalah Area. Kota/Grup dan Provinsi diturunkan lewat rantai
 * Location -> LocationArea -> LocationGroup -> Province dan tidak pernah
 * disimpan ulang di sini.
 *
 * Kolom alamat (village, district, city_regency, postal_code) TETAP ada dan
 * merupakan fakta pos, bukan hierarki. Satu Kota/Grup bertipe pemasaran boleh
 * mencakup beberapa kota/kabupaten, jadi alamat sungguhan tidak selalu bisa
 * disimpulkan dari nama induk.
 */
class Location extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_area_id',
        'name',
        'full_address',
        'village',
        'district',
        'city_regency',
        'postal_code',
        'landmark',
        'operational_hours_text',
        'whatsapp_number',
        'latitude',
        'longitude',
        'google_maps_url',
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
            // string, bukan float: presisi koordinat harus utuh apa adanya.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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

    public function area(): BelongsTo
    {
        return $this->belongsTo(LocationArea::class, 'location_area_id');
    }

    /**
     * Foto gerobak, SELALU dengan foto utama lebih dulu.
     *
     * Urutannya melekat pada relasi supaya eager load di jalur mana pun --
     * halaman publik, admin, structured data -- menghasilkan urutan yang sama.
     */
    public function images(): HasMany
    {
        return $this->hasMany(LocationImage::class)->ordered();
    }

    /**
     * Halaman slug yang menampilkan gerobak ini.
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(LocationPage::class, 'location_page_location')->withTimestamps();
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
     * Kota/Grup induk, lewat Area. Butuh eager load `area.group`.
     */
    public function parentGroup(): ?LocationGroup
    {
        return $this->area?->group;
    }

    /**
     * Provinsi induk, lewat Area dan Kota/Grup.
     * Butuh eager load `area.group.province`.
     */
    public function parentProvince(): ?Province
    {
        return $this->parentGroup()?->province;
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Visibilitas EFEKTIF: gerobak baru boleh tampil bila SELURUH leluhurnya
     * juga aktif -- Area, Kota/Grup, dan Provinsi. Gerobak aktif di bawah
     * induk nonaktif tidak boleh bocor ke halaman mana pun.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereHas('area', fn (Builder $area) => $area->effectivelyVisible());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Gerobak yang berada di bawah salah satu Kota/Grup tertentu.
     *
     * Inilah rumus kelayakan kandidat halaman slug:
     * location.area.location_group_id termasuk dalam Kota/Grup halaman.
     *
     * @param  list<int>  $groupIds
     */
    public function scopeInGroups(Builder $query, array $groupIds): Builder
    {
        if ($groupIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'area',
            fn (Builder $area) => $area->whereIn($area->qualifyColumn('location_group_id'), $groupIds)
        );
    }

    // ------------------------------------------------------------ visibility

    public function isActiveLocation(): bool
    {
        return ! $this->trashed() && (bool) $this->is_active;
    }

    /**
     * Termasuk memeriksa seluruh rantai induk. Memakai relasi yang sudah
     * dimuat bila tersedia supaya tidak memicu query tambahan.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isActiveLocation()) {
            return false;
        }

        return $this->area?->isEffectivelyVisible() ?? false;
    }

    // ------------------------------------------------------------- accessors

    /**
     * URL Maps yang sudah dipastikan aman, atau null.
     */
    public function safeMapsUrl(): ?string
    {
        return MapsUrl::resolve($this->latitude, $this->longitude, $this->google_maps_url);
    }
}
