<?php

namespace App\Support\V3;

use App\Models\SppgUnit;
use App\Models\User;

final class Navigation
{
    /** @return array<int, array<string, mixed>> */
    public function for(User $user, SppgUnit $unit): array
    {
        $groups = [
            $this->standalone('dashboard', 'Dashboard', 'home', [
                $this->item('Dashboard', 'home', route('v3.dashboard'), request()->routeIs('v3.dashboard'), $this->allowed($user, 'dashboard.view')),
            ]),
            $this->module('penerima', 'Penerima Manfaat', 'users', [
                $this->item('Data penerima', 'users', route('v3.beneficiaries.index'), request()->routeIs('v3.beneficiaries.*'), $this->allowed($user, 'beneficiaries.view')),
                $this->item('Jumlah penerima', 'calendar', route('v3.beneficiary-periods.index'), request()->routeIs('v3.beneficiary-periods.*'), $this->allowed($user, 'beneficiary_periods.view')),
            ]),
            $this->module('ahli-gizi', 'Ahli Gizi', 'nutrition', [
                $this->item('Perencanaan menu', 'calendar', route('v3.nutrition.menu-matrix'), request()->routeIs('v3.nutrition.menu-matrix') || request()->routeIs('v3.nutrition.menus.*'), $this->allowed($user, 'menus.view')),
                $this->item('Kebutuhan bahan', 'calculator', route('v3.nutrition.requirements.index'), request()->routeIs('v3.nutrition.requirements.*'), $this->allowed($user, 'nutrition.view')),
                $this->item('Evaluasi gizi harian', 'clipboard', route('v3.nutrition.daily-evaluation'), request()->routeIs('v3.nutrition.daily-evaluation'), $this->allowed($user, 'nutrition.view')),
                $this->item('Referensi gizi & bahan', 'settings', route('v3.nutrition.standards'), request()->routeIs('v3.nutrition.standards'), $this->allowed($user, 'nutrition.view') || $this->allowed($user, 'measurement_units.view')),
            ]),
            $this->module('pengadaan', 'Pengadaan', 'cart', [
                $this->item('Pengadaan bahan', 'clipboard', route('v3.procurement.index'), request()->routeIs('v3.procurement.*'), $this->allowed($user, 'procurement.view')),
            ]),
            $this->module('gudang', 'Gudang', 'box', [
                $this->item('Penerimaan bahan', 'box', route('v3.warehouse.receipts.index'), request()->routeIs('v3.warehouse.receipts.*'), $this->allowed($user, 'stock.view')),
                $this->item('Input stok awal', 'plus', route('v3.warehouse.opening-stocks.index'), request()->routeIs('v3.warehouse.opening-stocks.*'), $this->allowed($user, 'stock.create')),
                $this->item('Kartu stok', 'calculator', route('v3.warehouse.stock.index'), request()->routeIs('v3.warehouse.stock.*'), $this->allowed($user, 'stock.view')),
                $this->item('Pengambilan barang', 'arrow-up-right', route('v3.warehouse.withdrawals.index'), request()->routeIs('v3.warehouse.withdrawals.*'), $this->allowed($user, 'stock.view') || $this->allowed($user, 'preparation.view') || $this->allowed($user, 'processing.view') || $this->allowed($user, 'portioning.view')),
                $this->item('Kontrol stok', 'settings', route('v3.warehouse.controls.index'), request()->routeIs('v3.warehouse.controls.*'), $this->allowed($user, 'stock.view')),
            ]),
            $this->module('lapangan', 'Asisten Lapangan', 'route', [
                $this->item('Rencana distribusi', 'calendar', route('v3.field.plans.index'), request()->routeIs('v3.field.plans.*'), $this->allowed($user, 'field_planning.view')),
                $this->item('Laporan harian', 'clipboard', route('v3.field.daily-reports'), request()->routeIs('v3.field.daily-reports'), $this->allowed($user, 'field_daily_reports.view')),
                $this->item('Insiden lapangan', 'alert', route('v3.field.incidents.index'), request()->routeIs('v3.field.incidents.*'), $this->allowed($user, 'field_incidents.view')),
            ]),
            $this->module('persiapan', 'Persiapan', 'clipboard', [
                $this->item('Pekerjaan persiapan', 'clipboard', route('v3.preparation.index'), request()->routeIs('v3.preparation.*'), $this->allowed($user, 'preparation.view')),
                $this->item('Hasil persiapan', 'box', route('v3.preparation-outputs.index'), request()->routeIs('v3.preparation-outputs.*'), $this->allowed($user, 'preparation.view') || $this->allowed($user, 'processing.view') || $this->allowed($user, 'portioning.view')),
            ]),
            $this->module('pengolahan', 'Pengolahan', 'nutrition', [
                $this->item('Pekerjaan pengolahan', 'settings', route('v3.processing.index'), request()->routeIs('v3.processing.*'), $this->allowed($user, 'processing.view')),
            ]),
            $this->module('pemorsian', 'Pemorsian', 'calculator', [
                $this->item('Pekerjaan pemorsian', 'calculator', route('v3.portioning.index'), request()->routeIs('v3.portioning.*'), $this->allowed($user, 'portioning.view')),
            ]),
            $this->module('distribusi', 'Distribusi', 'truck', [
                $this->item('Pelaksanaan distribusi', 'truck', route('v3.operations.index', ['module' => 'distribusi']), request()->routeIs('v3.operations.*') && request()->route('module') === 'distribusi', $this->allowed($user, 'distribution.view')),
                $this->item('Pengambilan ompreng', 'arrow-up-right', route('v3.container-collections.index'), request()->routeIs('v3.container-collections.*'), $this->allowed($user, 'distribution.view')),
            ]),
            $this->module('pencucian', 'Pencucian', 'droplets', [
                $this->item('Pekerjaan pencucian', 'droplets', route('v3.operations.index', ['module' => 'pencucian']), request()->routeIs('v3.operations.*') && request()->route('module') === 'pencucian', $this->allowed($user, 'washing.view')),
            ]),
            $this->module('kebersihan', 'Kebersihan', 'sparkles', [
                $this->item('Pekerjaan kebersihan', 'sparkles', route('v3.operations.index', ['module' => 'kebersihan']), request()->routeIs('v3.operations.*') && request()->route('module') === 'kebersihan', $this->allowed($user, 'cleaning.view')),
            ]),
            $this->module('limbah', 'Limbah', 'recycle', [
                $this->item('Berita acara limbah', 'clipboard', route('v3.waste-handovers.index'), request()->routeIs('v3.waste-handovers.*'), $this->allowed($user, 'preparation.view') || $this->allowed($user, 'washing.view') || $this->allowed($user, 'cleaning.view')),
            ]),
            $this->module('keamanan', 'Keamanan', 'shield', [
                $this->item('Laporan keamanan', 'shield', route('v3.security.index'), request()->routeIs('v3.security.*'), $this->allowed($user, 'security.view')),
            ]),
            $this->module('kepegawaian', 'Kepegawaian', 'briefcase', [
                $this->item('Presensi relawan', 'users', route('v3.attendance.index'), request()->routeIs('v3.attendance.*'), $this->allowed($user, 'attendance.view')),
            ]),
            $this->module('administrasi', 'Administrasi Sistem', 'settings', [
                $this->item('Kirim notifikasi', 'alert', route('v3.notifications.broadcast'), request()->routeIs('v3.notifications.broadcast'), $this->allowed($user, 'notifications.manage')),
                $this->item('Master data', 'settings', route('v3.master-data.index'), request()->routeIs('v3.master-data.*'), $this->canSeeMasterData($user)),
            ]),
        ];

        return collect($groups)
            ->map(function (array $group): array {
                $group['items'] = array_values(array_filter(
                    $group['items'],
                    static fn (array $item): bool => $item['visible'],
                ));
                $group['active'] = collect($group['items'])->contains('active', true);

                return $group;
            })
            ->filter(static fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function module(string $key, string $label, string $icon, array $items): array
    {
        return compact('key', 'label', 'icon', 'items') + ['standalone' => false, 'active' => false];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function standalone(string $key, string $label, string $icon, array $items): array
    {
        return compact('key', 'label', 'icon', 'items') + ['standalone' => true, 'active' => false];
    }

    /** @return array<string, mixed> */
    private function item(string $label, string $icon, string $url, bool $active, bool $visible, bool $external = false): array
    {
        return compact('label', 'icon', 'url', 'active', 'visible', 'external');
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->is_super_admin || $user->can($permission);
    }

    private function canSeeMasterData(User $user): bool
    {
        foreach (['units.view', 'settings.manage', 'users.view', 'schools.view', 'posyandus.view', 'beneficiaries.view', 'measurement_units.view', 'allergens.view', 'ingredients.view', 'nutrition.view', 'suppliers.view', 'cleaning.view', 'divisions.view'] as $permission) {
            if ($this->allowed($user, $permission)) {
                return true;
            }
        }

        return false;
    }
}
