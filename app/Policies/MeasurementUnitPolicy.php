<?php

namespace App\Policies;

use App\Models\MeasurementUnit;
use App\Models\User;

class MeasurementUnitPolicy
{
    /**
     * Pengguna yang memiliki izin lihat dapat membuka daftar satuan.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('measurement_units.view');
    }

    /**
     * Master satuan bersifat global, sehingga tidak membutuhkan pemeriksaan tenant.
     */
    public function view(User $user, MeasurementUnit $measurementUnit): bool
    {
        return $user->can('measurement_units.view');
    }

    /**
     * Hanya Admin SPPG dan Ahli Gizi (atau role lain yang diberi izin manage)
     * yang boleh membuat satuan baru.
     */
    public function create(User $user): bool
    {
        return $user->can('measurement_units.manage');
    }

    /**
     * Hanya pemilik izin manage yang boleh mengubah master satuan.
     */
    public function update(User $user, MeasurementUnit $measurementUnit): bool
    {
        return $user->can('measurement_units.manage');
    }

    /**
     * Satuan hanya boleh dihapus bila belum dipakai bahan maupun resep.
     * Saat ini Resource tidak menampilkan tombol hapus, tetapi aturan ini
     * menjaga akses URL/action langsung tetap aman.
     */
    public function delete(User $user, MeasurementUnit $measurementUnit): bool
    {
        if (! $user->can('measurement_units.manage')) {
            return false;
        }

        return ! $measurementUnit->ingredients()->exists()
            && ! $measurementUnit->recipeIngredients()->exists();
    }

    /**
     * Model tidak memakai SoftDeletes.
     */
    public function restore(User $user, MeasurementUnit $measurementUnit): bool
    {
        return false;
    }

    /**
     * Penghapusan permanen tidak disediakan untuk master satuan.
     */
    public function forceDelete(User $user, MeasurementUnit $measurementUnit): bool
    {
        return false;
    }
}
