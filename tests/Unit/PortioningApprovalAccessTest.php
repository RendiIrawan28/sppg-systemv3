<?php

use App\Enums\UserRole;
use App\Support\AccessControl;

test('portioning verification and export follow operational oversight roles', function (): void {
    $headSppg = AccessControl::permissionsForRole(UserRole::KepalaSppg->value);
    $admin = AccessControl::permissionsForRole(UserRole::AdminSppg->value);
    $fieldAssistant = AccessControl::permissionsForRole(UserRole::AsistenLapangan->value);
    $nutritionist = AccessControl::permissionsForRole(UserRole::AhliGizi->value);
    $portioningHead = AccessControl::permissionsForRole(UserRole::KepalaDivisiPemorsian->value);
    $portioningStaff = AccessControl::permissionsForRole(UserRole::PetugasPemorsian->value);
    $viewer = AccessControl::permissionsForRole(UserRole::Viewer->value);

    expect($headSppg)
        ->toContain('portioning.approve')
        ->toContain('portioning.export')
        ->and($admin)
        ->toContain('portioning.export')
        ->and($fieldAssistant)
        ->toContain('portioning.export')
        ->not->toContain('portioning.approve')
        ->and($nutritionist)
        ->toContain('portioning.export')
        ->and($portioningHead)
        ->toContain('portioning.approve')
        ->toContain('portioning.export')
        ->and($portioningStaff)
        ->toContain('portioning.export')
        ->and($viewer)
        ->toContain('portioning.export');
});
