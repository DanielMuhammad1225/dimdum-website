<?php

namespace App\Policies;

use App\Enums\PanelPermission;
use App\Models\SocialLink;
use App\Models\User;

/**
 * Authorization tautan social media halaman Bio.
 *
 * Satu permission untuk seluruh aksi: daftar tautan ini kecil dan setiap
 * perubahannya -- menambah, mengubah, menonaktifkan, menghapus, maupun
 * mengurutkan -- sama-sama mengubah apa yang dibuka pengunjung dari profil
 * social media. Memecahnya lebih jauh tidak menambah perlindungan apa pun.
 *
 * Seluruh pemeriksaan berjalan di server. Akun nonaktif sudah ditolak lebih
 * dulu oleh Gate::before global.
 */
class SocialLinkPolicy
{
    protected function manages(User $user): bool
    {
        return $user->can(PanelPermission::ManageSocialLinks->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, SocialLink $link): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, SocialLink $link): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, SocialLink $link): bool
    {
        return $this->manages($user);
    }

    public function reorder(User $user): bool
    {
        return $this->manages($user);
    }
}
