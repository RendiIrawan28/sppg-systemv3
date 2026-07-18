<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Support\AccessControl;
use App\Support\DivisionRole;
use Tests\TestCase;

class AccessControlDefinitionTest extends TestCase
{
    public function test_every_defined_role_has_a_permission_map(): void
    {
        $roles = array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::cases(),
        );

        $this->assertEqualsCanonicalizing(
            $roles,
            array_keys(AccessControl::rolePermissions()),
        );
    }

    public function test_all_role_permissions_are_registered(): void
    {
        $registered = AccessControl::permissionNames();

        foreach (AccessControl::rolePermissions() as $role => $permissions) {
            $unknown = array_diff($permissions, $registered);
            $this->assertSame([], array_values($unknown), "Role {$role} memiliki permission yang belum didaftarkan.");
        }
    }

    public function test_division_staff_cannot_approve(): void
    {
        foreach (DivisionRole::staffRoleNames() as $role) {
            $permissions = AccessControl::permissionsForRole($role);
            $this->assertFalse(
                collect($permissions)->contains(fn (string $permission): bool => str_ends_with($permission, '.approve')),
                "Role {$role} tidak boleh memiliki permission approve.",
            );
        }
    }

    public function test_division_head_only_manages_its_own_operational_module(): void
    {
        foreach (DivisionRole::headRoleNames() as $role) {
            $ownDivision = DivisionRole::divisionCodeForRole($role);
            $ownPrefix = DivisionRole::permissionPrefixForDivision($ownDivision);
            $permissions = AccessControl::permissionsForRole($role);

            $this->assertContains("{$ownPrefix}.approve", $permissions);

            foreach (DivisionRole::DIVISIONS as $definition) {
                $otherPrefix = $definition['permission_prefix'];
                if ($otherPrefix === $ownPrefix) {
                    continue;
                }

                $this->assertNotContains("{$otherPrefix}.create", $permissions);
                $this->assertNotContains("{$otherPrefix}.update", $permissions);
                $this->assertNotContains("{$otherPrefix}.approve", $permissions);
            }
        }
    }

    public function test_operational_approval_separation_is_preserved(): void
    {
        $this->assertNotContains(
            'menus.approve',
            AccessControl::permissionsForRole(UserRole::AhliGizi->value),
        );

        $this->assertNotContains(
            'field_planning.approve',
            AccessControl::permissionsForRole(UserRole::AsistenLapangan->value),
        );

        $this->assertContains(
            'field_planning.submit',
            AccessControl::permissionsForRole(UserRole::AsistenLapangan->value),
        );

        $this->assertNotContains(
            'field_planning.activate',
            AccessControl::permissionsForRole(UserRole::AsistenLapangan->value),
        );

        $finance = AccessControl::permissionsForRole(UserRole::PengawasKeuangan->value);
        $this->assertContains('procurement.approve', $finance);
        $this->assertNotContains('procurement.create', $finance);
        $this->assertNotContains('procurement.order', $finance);
    }

    public function test_kepala_sppg_is_limited_to_monitoring_and_approval_actions(): void
    {
        $permissions = AccessControl::permissionsForRole(UserRole::KepalaSppg->value);

        foreach ([
            'head_dashboard.view',
            'menus.approve',
            'field_planning.view',
            'field_daily_reports.view',
            'preparation.approve',
            'processing.approve',
            'portioning.approve',
            'distribution.approve',
            'washing.approve',
            'cleaning.approve',
            'procurement.view',
            'stock.view',
            'finance.view',
        ] as $permission) {
            $this->assertContains($permission, $permissions);
        }

        foreach ([
            'units.view',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.assign_role',
            'settings.manage',
            'organization.manage',
            'procurement.create',
            'procurement.update',
            'procurement.delete',
            'procurement.submit',
            'procurement.approve',
            'procurement.order',
            'stock.create',
            'stock.update',
            'stock.delete',
            'stock.approve',
            'finance.create',
            'finance.update',
            'finance.verify',
            'finance.approve',
            'finance.close_period',
            'field_planning.create',
            'field_planning.update',
            'field_planning.delete',
            'field_planning.submit',
            'field_planning.activate',
            'field_planning.approve',
            'field_daily_reports.approve',
            'processing.create',
            'processing.update',
            'processing.delete',
            'processing.submit',
            'distribution.create',
            'distribution.update',
            'distribution.delete',
            'distribution.submit',
        ] as $permission) {
            $this->assertNotContains($permission, $permissions);
        }
    }

    public function test_removed_workflows_are_not_registered(): void
    {
        $permissions = AccessControl::permissionNames();

        foreach ([
            'head_approvals.view',
            'head_approvals.process',
            'head_reports.view',
            'head_reports.finalize',
            'field_planning.approve',
            'field_planning.activate',
            'field_daily_reports.create',
            'field_daily_reports.update',
            'field_daily_reports.submit',
            'field_daily_reports.approve',
        ] as $permission) {
            $this->assertNotContains($permission, $permissions);
        }
    }
}
