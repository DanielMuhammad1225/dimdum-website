<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductType;
use App\Services\LocationPageCatalogService;
use App\Services\ProductCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu produk katalog DIMDUM.
 *
 * Tidak punya slug dan tidak punya halaman detail: produk hanya muncul
 * sebagai kartu di homepage dan sebagai entri di /produk. Karena tidak ada
 * URL per produk, tidak ada pula yang perlu dialihkan saat namanya berubah.
 */
class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * sort_order TIDAK fillable. Urutan dihitung server -- posisi terakhir
     * saat dibuat, lalu hanya berubah lewat drag-and-drop. Nilai urutan yang
     * ikut dalam request tidak pernah dipercaya.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'type',
        'description',
        'price',
        'price_text',
        'image_path',
        'image_alt',
        'emoji',
        'is_active',
        'show_on_homepage',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'type' => ProductType::class,
            'price' => 'integer',
            'is_active' => 'boolean',
            'show_on_homepage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Setiap perubahan produk menaikkan DUA versi cache.
     *
     * Satu kait ini menutup SELURUH sebab yang disebutkan kebutuhan: data,
     * status aktif, saklar homepage, harga, dan foto semuanya berjalan lewat
     * save(). Yang tidak lewat sini hanya reorder -- Filament menyimpannya
     * dengan satu query update tanpa event model -- dan itu ditangani
     * afterReordering() pada tabelnya, yang juga menaikkan keduanya.
     */
    protected static function booted(): void
    {
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::{$event}(function (): void {
                ProductCatalogService::flushCache();

                /*
                 | Halaman Slug Lokasi kini ikut mencetak fakta produk, jadi
                 | perubahan produk membuat payload halaman itu basi juga.
                 | Dua versi cache dinaikkan bersamaan di sini, bukan salah
                 | satu: kalau hanya versi produk yang naik, halaman lokasi
                 | akan tetap menyajikan harga atau foto lama sampai TTL-nya
                 | habis.
                 */
                LocationPageCatalogService::flushCache();
            });
        }
    }

    // --------------------------------------------------------- relationships

    /**
     * Halaman Slug Lokasi yang menampilkan produk ini.
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(LocationPage::class, 'location_page_product')->withTimestamps();
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

    public function scopeOnHomepage(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('show_on_homepage'), true);
    }

    /**
     * Urutan publik: sort_order lalu nama, dengan id sebagai penentu terakhir
     * supaya hasilnya deterministik walau dua baris kembar.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }

    // ------------------------------------------------------------- accessors

    /**
     * Label harga yang boleh ditampilkan, atau null bila tidak ada harga.
     *
     * Aturannya satu arah dan tidak pernah dibalik:
     *   1. price_text menang bila diisi -- ia memuat satuan dan keterangan
     *      yang tidak bisa disimpulkan dari angka.
     *   2. bila kosong, price diformat ke Rupiah.
     *   3. bila keduanya kosong, hasilnya null dan pemanggil TIDAK MENCETAK
     *      apa pun. "Rp0" atau label kosong tidak pernah muncul.
     */
    public function priceLabel(): ?string
    {
        $text = is_string($this->price_text) ? trim($this->price_text) : '';

        if ($text !== '') {
            return $text;
        }

        if ($this->price === null) {
            return null;
        }

        return 'Rp'.number_format((int) $this->price, 0, ',', '.');
    }

    /**
     * Emoji fallback yang sudah dibersihkan, atau null.
     *
     * Hanya dipakai ketika produk tidak punya foto. Dibatasi pendek supaya
     * kolom ini tidak berubah menjadi tempat menaruh kalimat.
     */
    public function fallbackEmoji(): ?string
    {
        $emoji = is_string($this->emoji) ? trim($this->emoji) : '';

        return $emoji !== '' ? $emoji : null;
    }

    /**
     * Teks alternatif foto.
     *
     * Alt kosong pada gambar bermakna adalah cacat aksesibilitas, jadi nama
     * produk dipakai bila admin tidak menuliskannya sendiri -- bukan string
     * kosong.
     */
    public function imageAltText(): string
    {
        $alt = is_string($this->image_alt) ? trim($this->image_alt) : '';

        return $alt !== '' ? $alt : $this->name;
    }

    public function categoryLabel(): string
    {
        return $this->category instanceof ProductCategory ? $this->category->label() : '-';
    }

    public function typeLabel(): string
    {
        return $this->type instanceof ProductType ? $this->type->label() : '-';
    }
}
