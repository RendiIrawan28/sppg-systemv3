<?php

use App\Enums\UserRole;
use App\Support\AccessControl;

test('portioning verification and export use separate roles', function (): void {
    $headSppg = AccessControl::permissionsForRole(UserRole::KepalaSppg->value);
    $fieldAssistant = AccessControl::permissionsForRole(UserRole::AsistenLapangan->value);
    $portioningHead = AccessControl::permissionsForRole(UserRole::KepalaDivisiPemorsian->value);
    $portioningStaff = AccessControl::permissionsForRole(UserRole::PetugasPemorsian->value);

    expect($headSppg)
        ->toContain('portioning.approve')
        ->not->toContain('portioning.export')
        ->and($fieldAssistant)
        ->toContain('portioning.export')
        ->not->toContain('portioning.approve')
        ->and($portioningHead)
        ->toContain('portioning.approve')
        ->not->toContain('portioning.export')
        ->and($portioningStaff)
        ->not->toContain('portioning.export');
});
