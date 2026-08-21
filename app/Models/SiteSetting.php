<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton pengaturan website.
 *
 * Hanya ada satu baris, dikunci lewat kolom unik 'key'.
 */
class SiteSetting extends Model
{
    public const SINGLETON_KEY = 'default';

    public const CACHE_KEY = 'site_settings.default';

    /**
     * Mass-assignment dibatasi allowlist. 'key' dan 'id' sengaja TIDAK ada di
     * sini supaya singleton tidak bisa dipindah/diduplikasi lewat form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'brand_name',
        'tagline',
        'positioning',
        'whatsapp_number',
        'instagram_url',
        'tiktok_url',
        'facebook_url',
        'default_meta_title',
        'default_meta_description',
        'default_og_image_path',
        'updated_by',
    ];

    protected static function booted(): void
    {
        // Cache dibuang hanya untuk key milik model ini -- tidak pernah
        // menghapus seluruh cache aplikasi.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
