<?php

use App\Enums\UserRole;
use App\Models\WarehouseWithdrawalItem;
use App\Support\AccessControl;

it('grants operational monitoring only to the agreed management roles', function (): void {
    expect(AccessControl::permissionsForRole(UserRole::KepalaSppg->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AdminSppg->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AhliGizi->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::AsistenLapangan->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::PengawasKeuangan->value))
        ->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole('akuntan'))
        ->toContain('monitoring_operasional.view');

    expect(AccessControl::permissionsForRole(UserRole::StafGudang->value))
        ->not->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::PetugasPersiapan->value))
        ->not->toContain('monitoring_operasional.view')
        ->and(AccessControl::permissionsForRole(UserRole::Satpam->value))
        ->not->toContain('monitoring_operasional.view');
});

it('uses the actual warehouse withdrawal foreign key for monitoring queries', function (): void {
    expect((new WarehouseWithdrawalItem)->withdrawal()->getForeignKeyName())
        ->toBe('warehouse_withdrawal_id');
});
