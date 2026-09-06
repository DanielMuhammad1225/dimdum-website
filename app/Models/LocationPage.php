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
 *
 * Relasi ketiga berdiri sendiri dari keduanya:
 *
 *   products()  Produk yang dipilih untuk section produk halaman ini.
 *               Tidak ada hubungannya dengan cakupan wilayah, dan
 *               show_on_homepage pada produk tidak berpengaruh di sini.
 */
class LocationPage extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * slug TIDAK fillable: penggantiannya butuh permission khusus
     * (change_location_page_slugs) dan mencatat redirect, jadi ia melewati
     * jalur tersendiri -- bukan mass assignment.
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
        'show_products',
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
            'show_products' => 'boolean',
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

    /**
     * Produk yang dipilih untuk ditampilkan di halaman ini.
     *
     * Hanya relasi. Nama, kategori, harga, foto, dan deskripsi tetap dibaca
     * dari tabel products -- halaman ini tidak menyimpan salinannya.
     *
     * Relasi TIDAK ikut dihapus ketika show_products dimatikan: mematikan
     * saklar hanya menyembunyikan section, dan pilihan lama harus kembali
     * apa adanya saat saklarnya dinyalakan lagi.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'location_page_product')->withTimestamps();
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

    /**
     * Satu-satunya definisi visibilitas publik.
     *
     * Tidak terhapus (global scope SoftDeletes) DAN aktif DAN masih di dalam
     * periode bila periodenya diisi. Tidak ada jadwal terbit terpisah:
     * halaman aktif langsung dapat dibuka.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->active()->withinPeriod();
    }

    /**
     * Halaman yang dipilih untuk tampil di homepage.
     *
     * Kolomnya tetap bernama is_featured. Dulu ia hanya menentukan URUTAN --
     * halaman sorotan tampil lebih dulu -- sedangkan seluruh halaman yang
     * tampil publik ikut masuk homepage. Kini ia yang menentukan KEANGGOTAAN.
     *
     * Namanya sengaja TIDAK diganti dan kolom baru sengaja TIDAK dibuat:
     * keduanya menjawab pertanyaan yang sama persis, dan dua kolom untuk satu
     * pertanyaan hanya akan bisa saling bertentangan. Nama scope-nya yang
     * disesuaikan supaya kode terbaca sesuai artinya sekarang, sejalan dengan
     * Product::scopeOnHomepage().
     *
     * Saklar ini TIDAK memengaruhi bisa-tidaknya halaman dibuka lewat URL.
     */
    public function scopeOnHomepage(Builder $query): Builder
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

    /**
     * Alasan halaman ini belum terlihat pengunjung, atau null bila sudah.
     *
     * Dipakai admin panel supaya "kenapa URL saya 404" terjawab di tempat
     * kejadian, bukan ditebak. Aturannya tidak berbeda dari
     * isPubliclyVisible() -- hanya diperiksa satu per satu agar sebabnya
     * bisa disebutkan.
     */
    public function publicVisibilityIssue(): ?string
    {
        if ($this->trashed()) {
            return 'Halaman ini sudah dihapus, jadi URL-nya menghasilkan 404.';
        }

        if (! $this->is_active) {
            return 'Halaman ini NONAKTIF, jadi URL-nya menghasilkan 404. Aktifkan pada tab Publikasi.';
        }

        if ($this->starts_at instanceof Carbon && $this->starts_at->isFuture()) {
            return 'Halaman ini baru berlaku mulai '.$this->starts_at->translatedFormat('d F Y H:i')
                .'. Sampai saat itu URL-nya menghasilkan 404.';
        }

        if ($this->ends_at instanceof Carbon && $this->ends_at->isPast()) {
            return 'Periode halaman ini sudah berakhir pada '.$this->ends_at->translatedFormat('d F Y H:i')
                .', jadi URL-nya menghasilkan 404.';
        }

        return null;
    }

    /**
     * Label status untuk panel: AKTIF, NONAKTIF, atau DI LUAR PERIODE.
     */
    public function statusLabel(): string
    {
        if (! $this->is_active) {
            return 'NONAKTIF';
        }

        return $this->isWithinPeriod() ? 'AKTIF' : 'DI LUAR PERIODE';
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
