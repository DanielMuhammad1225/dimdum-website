<?php

namespace App\Models;

use App\Enums\LocationGroupType;
use App\Services\LocationPageCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kota/Grup -- tingkat kedua hierarki lokasi.
 *
 * MASTER DATA MURNI: tanpa slug, tanpa halaman, tanpa SEO.
 *
 * Perannya berubah menjadi penting secara berbeda: Kota/Grup adalah SATUAN
 * CAKUPAN yang dipilih Halaman Slug Lokasi. Memilih satu Kota/Grup berarti
 * seluruh gerobak di SEMUA Area di bawahnya menjadi kandidat halaman itu.
 */
class LocationGroup extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'province_id',
        'name',
        'type',
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
        static::saved(fn () => LocationPageCatalogService::flushCache());
        static::deleted(fn () => LocationPageCatalogService::flushCache());
        static::restored(fn () => LocationPageCatalogService::flushCache());
        static::forceDeleted(fn () => LocationPageCatalogService::flushCache());
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

    /**
     * Halaman slug yang memakai Kota/Grup ini sebagai cakupan.
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(LocationPage::class, 'location_page_group')->withTimestamps();
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
     * Grup yang benar-benar boleh menyumbang gerobak: aktif DAN provinsinya
     * aktif.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereHas('province', fn (Builder $province) => $province->active());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    // ------------------------------------------------------------ visibility

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

        return $this->province?->isEffectivelyVisible() ?? false;
    }

    // ------------------------------------------------------------- accessors

    public function typeLabel(): string
    {
        return $this->type instanceof LocationGroupType
            ? $this->type->label()
            : '-';
    }

    /**
     * Label yang tidak ambigu untuk dipilih admin.
     *
     * Nama Kota/Grup mudah berulang antar provinsi ("Selatan", "Kota"), jadi
     * provinsinya selalu ikut disebut. Butuh relasi `province` sudah dimuat.
     */
    public function qualifiedName(): string
    {
        $province = $this->province?->name;

        return $province ? $province.' — '.$this->name : $this->name;
    }
}
