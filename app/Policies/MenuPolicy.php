<?php

namespace App\Policies;

use App\Enums\MenuStatus;
use App\Models\Menu;
use App\Models\User;

class MenuPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('menus.view');
    }

    public function view(User $user, Menu $menu): bool
    {
        return $user->can('menus.view');
    }

    public function create(User $user): bool
    {
        return $user->can('menus.create');
    }

    /**
     * Editor resep memerlukan ability update.
     * Penyusun dapat membuka draft/revisi.
     * Ahli Gizi dapat membuka record terkunci untuk menjalankan action workflow.
     */
    public function update(User $user, Menu $menu): bool
    {
        if ($user->can('menus.update') && $menu->isEditable()) {
            return true;
        }

        return $user->can('menus.approve') && in_array(
            $menu->status,
            [
                MenuStatus::PendingReview,
                MenuStatus::Approved,
                MenuStatus::InUse,
            ],
            true
        );
    }

    public function delete(User $user, Menu $menu): bool
    {
        return $user->can('menus.delete')
            && $menu->status === MenuStatus::Draft
            && ! $menu->cycleDays()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Menu $menu): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Menu $menu): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
