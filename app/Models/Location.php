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
 * Gerobak DIMDUM.
 *
 * Slug sudah disimpan dan unik per wilayah supaya route detail
 * /lokasi/{area}/{location} bisa ditambahkan nanti. Route itu SENGAJA belum
 * ada pada fase ini.
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
        'filter_label',
        'full_address',
        'village',
        'district',
        'city_regency',
        'province',
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

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->published();
    }

    /**
     * Visibilitas EFEKTIF: gerobak baru tampil bila wilayah induknya juga
     * tampil. Gerobak aktif di bawah wilayah nonaktif tidak boleh bocor.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->publiclyVisible()
            ->whereHas('area', fn (Builder $area) => $area->publiclyVisible());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
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
     * Termasuk memeriksa wilayah induk. Memakai relasi yang sudah dimuat bila
     * tersedia supaya tidak memicu query tambahan.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isPubliclyVisible()) {
            return false;
        }

        return $this->area?->isPubliclyVisible() ?? false;
    }

    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    // ------------------------------------------------------------- accessors

    /**
     * Kelompok filter publik: filter_label, lalu district sebagai fallback.
     * Daftar filter TIDAK PERNAH di-hardcode di Blade.
     */
    public function filterGroup(): ?string
    {
        foreach ([$this->filter_label, $this->district] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * URL Maps yang sudah dipastikan aman, atau null.
     */
    public function safeMapsUrl(): ?string
    {
        return MapsUrl::resolve($this->latitude, $this->longitude, $this->google_maps_url);
    }
}
