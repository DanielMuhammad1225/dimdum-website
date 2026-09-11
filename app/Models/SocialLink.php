<?php

namespace App\Models;

use App\Enums\SocialIcon;
use App\Services\BioCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tautan social media pada halaman Bio.
 *
 * 'icon' hanya nama dari allowlist SocialIcon. Markup ikonnya hidup di kode,
 * jadi tidak pernah ada HTML maupun SVG dari admin yang sampai ke halaman.
 */
class SocialLink extends Model
{
    use HasFactory;

    /**
     * sort_order TIDAK fillable: urutan dihitung server (posisi terakhir saat
     * dibuat) lalu hanya berubah lewat drag-and-drop.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'icon',
        'is_active',
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
        ];
    }

    /**
     * Isi, status, maupun penghapusan tautan menaikkan versi cache Bio.
     * Reorder tidak lewat sini -- Filament menyimpannya dengan satu query
     * update tanpa event model -- dan ditangani afterReordering() tabelnya.
     */
    protected static function booted(): void
    {
        static::saved(fn () => BioCatalogService::flushCache());
        static::deleted(fn () => BioCatalogService::flushCache());
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('id'));
    }

    public function iconCase(): SocialIcon
    {
        return SocialIcon::resolve($this->icon);
    }
}
