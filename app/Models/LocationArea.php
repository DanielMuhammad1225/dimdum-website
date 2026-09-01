<?php

namespace App\Models;

use App\Services\LocationCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Wilayah landing DIMDUM -- area pemasaran publik, bukan wilayah administratif.
 *
 * Satu wilayah punya satu slug canonical dan menjadi destination iklan, jadi
 * URL-nya harus tetap hidup meski satu gerobak tutup atau pindah.
 */
class LocationArea extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Slug dan status publikasi TIDAK ada di sini: keduanya hanya boleh
     * berubah lewat jalur yang memeriksa permission dan mencatat redirect.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'headline',
        'description',
        'province',
        'city_regency',
        'seo_title',
        'seo_description',
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
        ];
    }

    protected static function booted(): void
    {
        // Setiap perubahan wilayah membuat seluruh turunan cache lokasi basi.
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
        static::restored(fn () => LocationCatalogService::flushCache());
        static::forceDeleted(fn () => LocationCatalogService::flushCache());
    }

    // --------------------------------------------------------- relationships

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function slugRedirects(): HasMany
    {
        return $this->hasMany(LocationAreaSlugRedirect::class);
    }

    /**
     * Foto seluruh gerobak di wilayah ini. Dipakai galeri halaman wilayah,
     * sehingga foto wilayah lain tidak mungkin ikut tercampur.
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

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sudah terbit: published_at terisi dan tidak berada di masa depan.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->published();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /**
     * Punya minimal satu gerobak yang benar-benar tampil di publik.
     */
    public function scopeHasVisibleLocations(Builder $query): Builder
    {
        return $query->whereHas('locations', fn (Builder $locations) => $locations->publiclyVisible());
    }

    // ------------------------------------------------------------ visibility

    /**
     * Visibilitas efektif, dihitung dari state model (bukan query ulang).
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        return $this->published_at instanceof Carbon && ! $this->published_at->isFuture();
    }

    /**
     * Pernah terbit? Menentukan apakah perubahan slug wajib mencatat redirect.
     */
    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    // ------------------------------------------------------------- accessors

    /**
     * Headline publik dengan fallback yang aman -- tidak pernah kosong dan
     * tidak memuat klaim yang belum terbukti.
     */
    public function publicHeadline(): string
    {
        $headline = is_string($this->headline) ? trim($this->headline) : '';

        return $headline !== '' ? $headline : 'Lokasi Gerobak DIMDUM di '.$this->name;
    }

    public function publicDescription(): string
    {
        $description = is_string($this->description) ? trim($this->description) : '';

        return $description !== ''
            ? $description
            : 'Temukan gerobak DIMDUM yang tersedia di wilayah '.$this->name.'.';
    }
}
