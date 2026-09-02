<?php

namespace App\Models;

use App\Services\LocationPageCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Halaman Slug Lokasi -- satu-satunya pemilik URL publik modul lokasi.
 *
 * Dua relasi dengan tugas berbeda:
 *
 *   groups()    CAKUPAN. Kota/Grup yang boleh menyumbang kandidat gerobak.
 *   locations() ISI. Gerobak yang benar-benar dipilih admin untuk tampil.
 *
 * Cakupan tidak otomatis menjadi isi. Gerobak baru yang muncul di Kota/Grup
 * yang sama TIDAK ikut tampil sampai admin memilihnya -- halaman iklan tidak
 * boleh berubah isinya sendiri.
 */
class LocationPage extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * slug dan published_at TIDAK fillable: keduanya butuh permission khusus
     * (change_location_page_slugs / publish_location_pages) dan diatur lewat
     * jalur tersendiri, bukan mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'short_description',
        'detailed_description',
        'period_text',
        'starts_at',
        'ends_at',
        'button_text',
        'button_url',
        'poster_path',
        'poster_alt',
        'poster_width',
        'poster_height',
        'poster_mime_type',
        'poster_size_bytes',
        'seo_title',
        'seo_description',
        'is_active',
        'is_featured',
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
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
            'poster_width' => 'integer',
            'poster_height' => 'integer',
            'poster_size_bytes' => 'integer',
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

    /**
     * Cakupan halaman.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(LocationGroup::class, 'location_page_group')->withTimestamps();
    }

    /**
     * Isi halaman: gerobak yang dipilih admin.
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_page_location')->withTimestamps();
    }

    public function slugRedirects(): HasMany
    {
        return $this->hasMany(LocationPageSlugRedirect::class);
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

    /**
     * Masih dalam periode berlaku.
     *
     * Kedua batas opsional: halaman tanpa tanggal berlaku selamanya.
     */
    public function scopeWithinPeriod(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where(fn (Builder $inner) => $inner
                ->whereNull($query->qualifyColumn('starts_at'))
                ->orWhere($query->qualifyColumn('starts_at'), '<=', $now))
            ->where(fn (Builder $inner) => $inner
                ->whereNull($query->qualifyColumn('ends_at'))
                ->orWhere($query->qualifyColumn('ends_at'), '>=', $now));
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->published()->withinPeriod();
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_featured'), true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('title'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Punya minimal satu gerobak terpilih yang masih benar-benar layak tampil.
     *
     * "Layak" berarti seluruh rantai induknya aktif DAN gerobak itu masih
     * berada di dalam cakupan Kota/Grup halaman ini.
     */
    public function scopeHasVisibleLocations(Builder $query): Builder
    {
        return $query->whereHas('locations', fn (Builder $locations) => $locations
            ->effectivelyVisible()
            ->whereHas('area', fn (Builder $area) => $area->whereIn(
                $area->qualifyColumn('location_group_id'),
                fn ($sub) => $sub
                    ->select('location_group_id')
                    ->from('location_page_group')
                    ->whereColumn('location_page_group.location_page_id', 'location_pages.id'),
            )));
    }

    // ------------------------------------------------------------ visibility

    public function isPubliclyVisible(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        if (! $this->published_at instanceof Carbon || $this->published_at->isFuture()) {
            return false;
        }

        return $this->isWithinPeriod();
    }

    public function isWithinPeriod(): bool
    {
        $now = now();

        if ($this->starts_at instanceof Carbon && $this->starts_at->greaterThan($now)) {
            return false;
        }

        return ! ($this->ends_at instanceof Carbon && $this->ends_at->lessThan($now));
    }

    public function hasEverBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Alasan halaman ini BELUM terlihat pengunjung, atau null bila sudah.
     *
     * Dipakai admin panel supaya "kenapa URL saya 404" terjawab di tempat
     * kejadian, bukan ditebak. Aturannya tidak berbeda dari
     * isPubliclyVisible() -- hanya diperiksa satu per satu agar bisa
     * disebutkan sebabnya.
     */
    public function publicVisibilityIssue(): ?string
    {
        if ($this->trashed()) {
            return 'Halaman ini sudah dihapus, jadi URL-nya menghasilkan 404.';
        }

        if (! $this->is_active) {
            return 'Halaman ini NONAKTIF, jadi URL-nya menghasilkan 404. Aktifkan pada tab Publikasi.';
        }

        if (! $this->published_at instanceof Carbon) {
            return 'Halaman ini masih DRAFT: kolom "Waktu terbit" belum diisi, jadi URL-nya menghasilkan 404. '
                .'Isi waktu terbit pada tab Publikasi agar halaman dapat dibuka pengunjung.';
        }

        if ($this->published_at->isFuture()) {
            return 'Halaman ini DIJADWALKAN terbit pada '.$this->published_at->translatedFormat('d F Y H:i')
                .'. Sampai waktu itu URL-nya masih menghasilkan 404.';
        }

        if (! $this->isWithinPeriod()) {
            return 'Halaman ini berada DI LUAR PERIODE berlakunya, jadi URL-nya menghasilkan 404. '
                .'Periksa tanggal mulai dan selesai pada tab Isi Halaman.';
        }

        return null;
    }

    // ------------------------------------------------------------- accessors

    public function publicTitle(): string
    {
        $title = is_string($this->seo_title) ? trim($this->seo_title) : '';

        return $title !== '' ? $title : $this->title;
    }

    public function publicDescription(): string
    {
        foreach ([$this->seo_description, $this->short_description] as $candidate) {
            $text = is_string($candidate) ? trim($candidate) : '';

            if ($text !== '') {
                return $text;
            }
        }

        return 'Lihat titik gerobak DIMDUM pada halaman '.$this->title.'.';
    }
}
