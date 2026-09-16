<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\Product;
use App\Models\User;

/**
 * Authorization modul Produk.
 *
 * Seluruh pemeriksaan berjalan di server. Menyembunyikan menu saja tidak
 * pernah dianggap sebagai proteksi. Akun nonaktif sudah ditolak lebih dulu
 * oleh Gate::before global.
 *
 * Operator mengurus isi katalog sehari-hari: melihat, menambah, mengubah, dan
 * mengganti foto. Menghapus tidak termasuk, dan menghapus permanen dijaga
 * permission tersendiri -- lihat PanelPermission::productCases().
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PanelPermission::ViewProducts->value);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::ViewProducts->value);
    }

    public function create(User $user): bool
    {
        return $user->can(PanelPermission::CreateProducts->value);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::UpdateProducts->value);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::DeleteProducts->value);
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::DeleteProducts->value);
    }

    /**
     * Menghapus permanen ikut membuang berkas fotonya dan tidak dapat
     * dibatalkan, jadi izinnya terpisah dari hapus biasa.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::ForceDeleteProducts->value);
    }

    public function manageMedia(User $user, Product $product): bool
    {
        return $user->can(PanelPermission::ManageProductMedia->value);
    }

    /**
     * Menata urutan mengubah tampilan homepage dan halaman /produk, jadi ia
     * mengikuti permission mengubah -- bukan sekadar "bisa melihat".
     */
    public function reorder(User $user): bool
    {
        return $user->can(PanelPermission::UpdateProducts->value);
    }
}
