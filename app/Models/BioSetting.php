<?php

namespace App\Models;

use App\Enums\BioLocationMode;
use App\Services\BioCatalogService;
use App\Services\BioSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton pengaturan halaman Bio.
 *
 * Hanya ada satu baris, dikunci lewat kolom unik 'key' -- pola yang sama
 * dengan SiteSetting dan HomepageSetting.
 */
class BioSetting extends Model
{
    public const SINGLETON_KEY = 'default';

    /**
     * Mass-assignment dibatasi allowlist. 'key' dan 'id' sengaja TIDAK ada di
     * sini supaya singleton tidak bisa dipindah atau diduplikasi lewat form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'is_active',
        'title',
        'description',
        'location_mode',
        'whatsapp_number',
        'whatsapp_message',
        'buttons',
        'updated_by',
    ];

    /**
     * 'location_mode' SENGAJA tidak di-cast ke BioLocationMode.
     *
     * Cast enum melempar exception begitu nilai di database tidak dikenal --
     * misalnya ditulis langsung lewat SQL, atau tersisa dari mode yang kelak
     * dihapus -- dan exception itu terjadi saat halaman PUBLIK membacanya.
     * Nilainya selalu diselesaikan lewat BioSettingsService::
     * resolveLocationMode(), yang jatuh ke mode bawaan alih-alih error.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'buttons' => 'array',
        ];
    }

    public function locationMode(): BioLocationMode
    {
        return BioSettingsService::resolveLocationMode($this->location_mode);
    }

    /**
     * Setiap perubahan menaikkan versi cache Bio. Cache aplikasi lain tidak
     * disentuh sama sekali.
     */
    protected static function booted(): void
    {
        static::saved(fn () => BioCatalogService::flushCache());
        static::deleted(fn () => BioCatalogService::flushCache());
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
