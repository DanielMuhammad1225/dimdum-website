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
 * Provinsi -- tingkat teratas hierarki lokasi.
 *
 * Provinsi menjadi segmen pertama URL landing, jadi slug-nya ikut menentukan
 * URL setiap Area di bawahnya. Karena itu perlakuannya sama ketatnya dengan
 * Area: slug tidak fillable, dan penggantiannya mencatat redirect 301.
 */
class Province extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * slug dan published_at TIDAK fillable: keduanya hanya boleh berubah
     * lewat jalur yang memeriksa permission dan mencatat redirect.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
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
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
        static::restored(fn () => LocationCatalogService::flushCache());
        static::forceDeleted(fn () => LocationCatalogService::flushCache());
    }

    // --------------------------------------------------------- relationships

    public function groups(): HasMany
    {
        return $this->hasMany(LocationGroup::class);
    }

    public function slugRedirects(): HasMany
    {
        return $this->hasMany(LocationProvinceSlugRedirect::class);
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

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Punya minimal satu Area yang benar-benar tampil DAN berisi gerobak.
     *
     * Rantainya diperiksa penuh: grup aktif -> area visible -> gerobak
     * visible. Provinsi yang hanya berisi grup kosong tidak layak muncul di
     * daftar publik.
     */
    public function scopeHasVisibleAreas(Builder $query): Builder
    {
        return $query->whereHas(
            'groups',
            fn (Builder $groups) => $groups
                ->active()
                ->whereHas(
                    'areas',
                    fn (Builder $areas) => $areas
                        ->publiclyVisible()
                        ->whereHas('locations', fn (Builder $locations) => $locations->publiclyVisible())
                )
        );
    }

    // ------------------------------------------------------------ visibility

    public function isPubliclyVisible(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        return $this->published_at instanceof Carbon && ! $this->published_at->isFuture();
    }

    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    // ------------------------------------------------------------- accessors

    public function publicHeadline(): string
    {
        $title = is_string($this->seo_title) ? trim($this->seo_title) : '';

        return $title !== '' ? $title : 'Lokasi Gerobak DIMDUM di '.$this->name;
    }

    public function publicDescription(): string
    {
        $description = is_string($this->description) ? trim($this->description) : '';

        return $description !== ''
            ? $description
            : 'Pilih area di '.$this->name.' untuk melihat titik gerobak DIMDUM yang tersedia.';
    }
}
