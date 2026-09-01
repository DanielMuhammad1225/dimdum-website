<?php

namespace App\Models;

use App\Services\LocationCatalogService;
use App\Support\MapsUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Gerobak DIMDUM -- titik fisik, tingkat terbawah hierarki.
 *
 * Induknya adalah Area. Kota/Grup dan Provinsi diturunkan lewat rantai
 * Location -> LocationArea -> LocationGroup -> Province dan tidak pernah
 * disimpan ulang di sini.
 *
 * Kolom alamat (village, district, city_regency, postal_code) TETAP ada dan
 * merupakan fakta pos, bukan hierarki. Satu Kota/Grup bertipe pemasaran boleh
 * mencakup beberapa kota/kabupaten, jadi alamat sungguhan tidak selalu bisa
 * disimpulkan dari nama induk.
 *
 * Slug sudah disimpan dan unik per area supaya route detail
 * /lokasi/{province}/{area}/{location} bisa ditambahkan nanti. Route itu
 * SENGAJA belum ada pada fase ini.
 */
class Location extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * slug dan published_at tidak fillable: keduanya butuh permission khusus
     * (change_location_slugs / publish_locations) dan diatur lewat jalur
     * tersendiri, bukan mass assignment.
     *
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
            'published_at' => 'datetime',
            'sort_order' => 'integer',
            // string, bukan float: presisi koordinat harus utuh apa adanya.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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

    public function area(): BelongsTo
    {
        return $this->belongsTo(LocationArea::class, 'location_area_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(LocationImage::class);
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

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull($query->qualifyColumn('published_at'))
            ->where($query->qualifyColumn('published_at'), '<=', now());
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->published();
    }

    /**
     * Visibilitas EFEKTIF: gerobak baru tampil bila SELURUH leluhurnya juga
     * tampil -- Area terbit, Kota/Grup aktif, dan Provinsi terbit. Gerobak
     * aktif di bawah induk tersembunyi tidak boleh bocor.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->publiclyVisible()
            ->whereHas('area', fn (Builder $area) => $area->effectivelyVisible());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    // ------------------------------------------------------------ visibility

    public function isPubliclyVisible(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        return $this->published_at instanceof Carbon && ! $this->published_at->isFuture();
    }

    /**
     * Termasuk memeriksa seluruh rantai induk. Memakai relasi yang sudah
     * dimuat bila tersedia supaya tidak memicu query tambahan.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isPubliclyVisible()) {
            return false;
        }

        return $this->area?->isEffectivelyVisible() ?? false;
    }

    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null;
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
