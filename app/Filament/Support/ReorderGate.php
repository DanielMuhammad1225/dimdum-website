<?php

namespace App\Filament\Support;

use App\Enums\PanelPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Penentu kapan drag-and-drop urutan boleh aktif pada sebuah tabel.
 *
 * Dua lapis, keduanya di server:
 *
 *  1. PERMISSION. Menata urutan mengubah tampilan publik, jadi ia mengikuti
 *     permission pengelola tingkat bersangkutan -- bukan sekadar "bisa
 *     melihat".
 *
 *  2. SATU SCOPE PENUH. Urutan hanya bermakna di dalam satu induk. Selama
 *     tabel masih mencampur anak dari beberapa induk -- atau sedang disaring
 *     pencarian sehingga yang tampil cuma sebagian -- menyeret baris akan
 *     menghasilkan nomor yang tidak konsisten dengan data yang tidak terlihat.
 *     Karena itu reorder baru terbuka setelah tepat satu induk dipilih.
 *
 * Filament menutup sebagian: reorderTable() menolak jalan bila
 * isReorderable() bernilai false, sehingga kedua syarat di atas berlaku
 * server-side, bukan sekadar menyembunyikan tombol.
 *
 * Yang TIDAK ditutup Filament: perintah update reorder dirakit dari
 * Table::getQuery(), dan getQuery() hanya menerapkan query scope -- filter
 * tabel tidak ikut. Jadi menyaring tampilan saja tidak cukup, dan keanggotaan
 * induk tetap harus diperiksa sendiri lewat assertBelongsToSelectedParent().
 */
class ReorderGate
{
    /**
     * Apakah user boleh menata urutan pada tingkat ini?
     */
    public static function permits(PanelPermission $permission): bool
    {
        return auth()->user()?->can($permission->value) ?? false;
    }

    /**
     * Apakah tabel sedang menampilkan tepat satu scope yang utuh?
     *
     * @param  string|null  $parentFilter  nama filter induk; null untuk tingkat
     *                                     teratas yang memang berurutan global
     */
    public static function showsOneCompleteScope(mixed $livewire, ?string $parentFilter): bool
    {
        if (! is_object($livewire)) {
            return false;
        }

        // Pencarian menyembunyikan sebagian baris -- urutan hasil seretan
        // tidak akan mewakili keseluruhan scope.
        if (method_exists($livewire, 'getTableSearch') && filled($livewire->getTableSearch())) {
            return false;
        }

        if ($parentFilter === null) {
            return true;
        }

        if (! method_exists($livewire, 'getTableFilterState')) {
            return false;
        }

        $state = $livewire->getTableFilterState($parentFilter);

        return filled($state['value'] ?? null);
    }

    /**
     * Gabungan keduanya: izin DAN satu scope utuh.
     */
    public static function allows(mixed $livewire, PanelPermission $permission, ?string $parentFilter): bool
    {
        return self::permits($permission)
            && self::showsOneCompleteScope($livewire, $parentFilter);
    }

    /**
     * Pastikan SELURUH id pada payload reorder benar-benar milik induk yang
     * sedang dipilih.
     *
     * Ini bukan pengulangan dari filter tabel. Filament menyusun perintah
     * update reorder dari Table::getQuery(), yang hanya menerapkan query scope
     * -- FILTER TIDAK IKUT. Tanpa pemeriksaan ini, id milik induk lain yang
     * disisipkan ke payload akan ikut ditulis ulang urutannya.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int|string>  $order
     */
    public static function assertBelongsToSelectedParent(
        mixed $livewire,
        array $order,
        string $modelClass,
        string $parentColumn,
        string $parentFilter,
    ): void {
        if ($order === []) {
            return;
        }

        $state = is_object($livewire) && method_exists($livewire, 'getTableFilterState')
            ? $livewire->getTableFilterState($parentFilter)
            : null;

        $parentId = $state['value'] ?? null;

        if (blank($parentId)) {
            throw new AuthorizationException(
                'Urutan hanya dapat diatur setelah satu induk dipilih pada filter.'
            );
        }

        $foreign = $modelClass::query()
            ->whereIn((new $modelClass)->getKeyName(), array_values($order))
            ->where($parentColumn, '!=', $parentId)
            ->exists();

        if ($foreign) {
            throw new AuthorizationException(
                'Permintaan pengaturan urutan memuat data dari induk lain dan ditolak.'
            );
        }
    }

    /**
     * Petunjuk singkat yang ditampilkan ketika reorder belum bisa dipakai.
     */
    public static function hint(string $parentLabel): string
    {
        return "Pilih satu {$parentLabel} pada filter di atas untuk mengatur urutan dengan seret dan lepas.";
    }
}
