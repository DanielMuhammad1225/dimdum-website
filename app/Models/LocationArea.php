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
 * Area -- tingkat ketiga hierarki lokasi, dan induk langsung gerobak.
 *
 * Area adalah satuan operasional/pemasaran, bukan selalu kecamatan. Ia berada
 * di bawah satu Kota/Grup, dan provinsinya diturunkan lewat rantai
 * Area -> LocationGroup -> Province -- tidak pernah disimpan ulang di sini.
 *
 * Halaman Area menjadi destination iklan (/lokasi/{province}/{area}), jadi
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
        'location_group_id',
        'name',
        'headline',
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
        // Setiap perubahan area membuat seluruh turunan cache lokasi basi.
        static::saved(fn () => LocationCatalogService::flushCache());
        static::deleted(fn () => LocationCatalogService::flushCache());
        static::restored(fn () => LocationCatalogService::flushCache());
        static::forceDeleted(fn () => LocationCatalogService::flushCache());
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

    public function slugRedirects(): HasMany
    {
        return $this->hasMany(LocationAreaSlugRedirect::class);
    }

    /**
     * Foto seluruh gerobak di area ini. Dipakai galeri halaman area,
     * sehingga foto area lain tidak mungkin ikut tercampur.
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
     * Sengaja BUKAN relationship dan bukan accessor bernama `province`:
     * memanggilnya tanpa eager load `group.province` akan memicu lazy load,
     * dan di environment testing Model::preventLazyLoading() menjadikannya
     * exception -- sehingga N+1 ketahuan di test, bukan di production.
     */
    public function parentProvince(): ?Province
    {
        return $this->group?->province;
    }

    // ---------------------------------------------------------------- scopes

    /*
     | Seluruh scope memakai kolom BERKUALIFIKASI (nama tabel disertakan).
     | location_areas, location_groups, dan provinces sama-sama punya kolom
     | is_active dan sort_order, sehingga scope tanpa kualifikasi menjadi
     | ambigu begitu ikut dalam JOIN atau whereHas bertingkat.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Sudah terbit: published_at terisi dan tidak berada di masa depan.
     */
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
     * Visibilitas EFEKTIF: area baru tampil bila Kota/Grup-nya aktif DAN
     * provinsinya tampil. Area aktif di bawah provinsi draft tidak boleh
     * bocor lewat URL mana pun.
     */
    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->publiclyVisible()
            ->whereHas(
                'group',
                fn (Builder $group) => $group
                    ->active()
                    ->whereHas('province', fn (Builder $province) => $province->publiclyVisible())
            );
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
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
     * Visibilitas area itu sendiri, tanpa memeriksa induk.
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        return $this->published_at instanceof Carbon && ! $this->published_at->isFuture();
    }

    /**
     * Termasuk seluruh rantai induk: Kota/Grup aktif dan provinsi tampil.
     */
    public function isEffectivelyVisible(): bool
    {
        if (! $this->isPubliclyVisible()) {
            return false;
        }

        return $this->group?->isEffectivelyVisible() ?? false;
    }

    /**
     * Pernah terbit? Menentukan apakah perubahan slug wajib mencatat redirect
     * dan apakah perpindahan lintas provinsi boleh ditolak.
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
            : 'Temukan gerobak DIMDUM yang tersedia di area '.$this->name.'.';
    }
}
