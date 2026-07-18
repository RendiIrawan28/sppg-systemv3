<?php

namespace App\Support\V3;

use App\Models\SppgUnit;
use App\Models\User;

final class Navigation
{
    /** @return array<int, array{label: string, items: array<int, array<string, mixed>>}> */
    public function for(User $user, SppgUnit $unit): array
    {
        $groups = [
            [
                'label' => 'Ruang kerja',
                'items' => [
                    $this->item(
                        label: 'Dashboard',
                        icon: 'home',
                        url: route('v3.dashboard'),
                        active: request()->routeIs('v3.dashboard'),
                        visible: $this->allowed($user, 'dashboard.view'),
                    ),
                    $this->item(
                        label: 'Penerima manfaat',
                        icon: 'users',
                        url: route('v3.beneficiaries.index'),
                        active: request()->routeIs('v3.beneficiaries.*'),
                        visible: $this->allowed($user, 'beneficiaries.view'),
                        badge: 'V3',
                    ),
                    $this->item(
                        label: 'Periode penerima',
                        icon: 'calendar',
                        url: route('v3.beneficiary-periods.index'),
                        active: request()->routeIs('v3.beneficiary-periods.*'),
                        visible: $this->allowed($user, 'beneficiary_periods.view'),
                        badge: '14 hari',
                    ),
                    $this->item(
                        label: 'Perencanaan menu',
                        icon: 'calendar',
                        url: route('v3.nutrition.menu-matrix'),
                        active: request()->routeIs('v3.nutrition.menu-matrix'),
                        visible: $this->allowed($user, 'menus.view'),
                        badge: 'Matriks',
                    ),
                    $this->item(
                        label: 'Kebutuhan & pengadaan',
                        icon: 'calculator',
                        url: route('v3.nutrition.requirements.index'),
                        active: request()->routeIs('v3.nutrition.requirements.*'),
                        visible: $this->allowed($user, 'nutrition.view'),
                        badge: 'Otomatis',
                    ),
                    $this->item(
                        label: 'Evaluasi gizi harian',
                        icon: 'clipboard',
                        url: route('v3.nutrition.daily-evaluation'),
                        active: request()->routeIs('v3.nutrition.daily-evaluation'),
                        visible: $this->allowed($user, 'nutrition.view'),
                        badge: 'Gabung',
                    ),
                    $this->item(
                        label: 'Referensi gizi & bahan',
                        icon: 'settings',
                        url: route('v3.nutrition.standards'),
                        active: request()->routeIs('v3.nutrition.standards'),
                        visible: $this->allowed($user, 'nutrition.view')
                            || $this->allowed($user, 'measurement_units.view'),
                        badge: 'Gabung',
                    ),
                    $this->item(
                        label: 'Pengadaan bahan',
                        icon: 'clipboard',
                        url: route('v3.procurement.index'),
                        active: request()->routeIs('v3.procurement.*'),
                        visible: $this->allowed($user, 'procurement.view'),
                        badge: 'Workflow',
                    ),
                    $this->item(
                        label: 'Penerimaan bahan',
                        icon: 'box',
                        url: route('v3.warehouse.receipts.index'),
                        active: request()->routeIs('v3.warehouse.receipts.*'),
                        visible: $this->allowed($user, 'stock.view'),
                        badge: 'QC',
                    ),
                    $this->item(
                        label: 'Kartu stok',
                        icon: 'calculator',
                        url: route('v3.warehouse.stock.index'),
                        active: request()->routeIs('v3.warehouse.stock.*'),
                        visible: $this->allowed($user, 'stock.view'),
                    ),
                    $this->item(
                        label: 'Serah bahan',
                        icon: 'arrow-up-right',
                        url: route('v3.warehouse.handovers.index'),
                        active: request()->routeIs('v3.warehouse.handovers.*'),
                        visible: $this->allowed($user, 'stock.view') || $this->allowed($user, 'preparation.view'),
                        badge: 'Gudang → Persiapan',
                    ),
                    $this->item(
                        label: 'Rencana lapangan', icon: 'calendar', url: route('v3.field.plans.index'),
                        active: request()->routeIs('v3.field.plans.*'), visible: $this->allowed($user, 'field_planning.view'),
                        badge: 'H-3',
                    ),
                    $this->item(
                        label: 'Laporan harian', icon: 'clipboard', url: route('v3.field.daily-reports'),
                        active: request()->routeIs('v3.field.daily-reports'), visible: $this->allowed($user, 'field_daily_reports.view'),
                        badge: 'Otomatis',
                    ),
                    $this->item(
                        label: 'Insiden lapangan', icon: 'alert', url: route('v3.field.incidents.index'),
                        active: request()->routeIs('v3.field.incidents.*'), visible: $this->allowed($user, 'field_incidents.view'),
                    ),
                    $this->item(
                        label: 'Pengolahan', icon: 'settings', url: route('v3.operations.index', ['module' => 'pengolahan']),
                        active: request()->routeIs('v3.operations.*') && request()->route('module') === 'pengolahan', visible: $this->allowed($user, 'processing.view'),
                    ),
                    $this->item(
                        label: 'Pemorsian', icon: 'calculator', url: route('v3.operations.index', ['module' => 'pemorsian']),
                        active: request()->routeIs('v3.operations.*') && request()->route('module') === 'pemorsian', visible: $this->allowed($user, 'portioning.view'),
                    ),
                    $this->item(
                        label: 'Distribusi', icon: 'arrow-up-right', url: route('v3.operations.index', ['module' => 'distribusi']),
                        active: request()->routeIs('v3.operations.*') && request()->route('module') === 'distribusi', visible: $this->allowed($user, 'distribution.view'),
                    ),
                    $this->item(
                        label: 'Pencucian', icon: 'box', url: route('v3.operations.index', ['module' => 'pencucian']),
                        active: request()->routeIs('v3.operations.*') && request()->route('module') === 'pencucian', visible: $this->allowed($user, 'washing.view'),
                    ),
                    $this->item(
                        label: 'Kebersihan', icon: 'home', url: route('v3.operations.index', ['module' => 'kebersihan']),
                        active: request()->routeIs('v3.operations.*') && request()->route('module') === 'kebersihan', visible: $this->allowed($user, 'cleaning.view'),
                    ),
                    $this->item(
                        label: 'Master data',
                        icon: 'settings',
                        url: route('v3.master-data.index'),
                        active: request()->routeIs('v3.master-data.*'),
                        visible: $this->canSeeMasterData($user),
                        badge: 'Native V3',
                    ),
                ],
            ],
        ];

        return collect($groups)
            ->map(function (array $group): array {
                $group['items'] = array_values(array_filter(
                    $group['items'],
                    static fn (array $item): bool => $item['visible'],
                ));

                return $group;
            })
            ->filter(static fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();
    }

    /** @return array<int, array{label: string, description: string, state: string}> */
    public function roadmap(User $user): array
    {
        $items = [
            ['permission' => 'dashboard.view', 'label' => 'Dashboard lintas proses', 'description' => 'Ringkasan unit dan denyut operasional harian.', 'state' => 'active'],
            ['permission' => 'beneficiaries.view', 'label' => 'Penerima manfaat', 'description' => 'Form, impor Excel, alergi, dan periode 14 hari sudah native V3.', 'state' => 'active'],
            ['permission' => 'menus.view', 'label' => 'Menu & gizi', 'description' => 'Matriks menu, kebutuhan bahan, standar, evaluasi, dan laporan harian tersedia di V3.', 'state' => 'active'],
            ['permission' => 'procurement.view', 'label' => 'Pengadaan & gudang', 'description' => 'Permintaan, penerimaan, QC, kartu stok, dan serah bahan tersedia di V3.', 'state' => 'active'],
            ['permission' => 'field_planning.view', 'label' => 'Rencana lapangan', 'description' => 'Rencana H-3, tujuan, insiden, dan laporan otomatis tersedia di V3.', 'state' => 'active'],
            ['permission' => 'processing.view', 'label' => 'Operasional dapur', 'description' => 'Pengolahan sampai pencucian dan kebersihan tersedia di V3.', 'state' => 'active'],
        ];

        return array_values(array_map(
            static function (array $item): array {
                unset($item['permission']);

                return $item;
            },
            array_filter(
                $items,
                fn (array $item): bool => $this->allowed($user, $item['permission']),
            ),
        ));
    }

    /** @return array<string, mixed> */
    private function item(
        string $label,
        string $icon,
        string $url,
        bool $active,
        bool $visible,
        ?string $badge = null,
        bool $external = false,
    ): array {
        return compact('label', 'icon', 'url', 'active', 'visible', 'badge', 'external');
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
