<?php

use App\Enums\UserRole;
use App\Support\AccessControl;
use App\Support\Mobile\MobileWorkspaceRegistry;

it('exposes the missing mobile workflows for processing returns and distribution incidents', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    expect($definitions)
        ->toHaveKeys(['gudang-retur-pengolahan', 'pengolahan', 'distribusi', 'lapangan-insiden'])
        ->and($definitions['pengolahan']['relations'])->toHaveKey('returns')
        ->and($definitions['distribusi']['relations'])->toHaveKey('incidents');

    $returnFields = collect($definitions['pengolahan']['relations']['returns']['fields'])->pluck('name');
    expect($returnFields)
        ->toContain('processing_material_usage_id')
        ->toContain('requested_quantity')
        ->toContain('reason')
        ->toContain('photo_path');
});

it('grants all field divisions mobile incident permissions', function (): void {
    $roles = [
        UserRole::StafGudang,
        UserRole::KepalaDivisiPersiapan,
        UserRole::PetugasPersiapan,
        UserRole::KepalaDivisiPengolahan,
        UserRole::PetugasPengolahan,
        UserRole::KepalaDivisiPemorsian,
        UserRole::PetugasPemorsian,
        UserRole::KepalaDivisiDistribusi,
        UserRole::PetugasDistribusi,
        UserRole::KepalaDivisiPencucian,
        UserRole::PetugasPencucian,
        UserRole::KepalaDivisiKebersihan,
        UserRole::PetugasKebersihan,
        UserRole::Satpam,
    ];

    foreach ($roles as $role) {
        expect(AccessControl::permissionsForRole($role->value))
            ->toContain('field_incidents.view')
            ->toContain('field_incidents.create')
            ->toContain('field_incidents.update')
            ->toContain('field_incidents.resolve');
    }
});

it('routes distribution corrections and processing returns through domain workflows', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/Api/MobileOperationalController.php'));

    expect($controller)
        ->toContain('ProcessingReturnService::class')
        ->toContain('gudang-retur-pengolahan')
        ->toContain('->reviseStop(')
        ->toContain("'incidents' => ['create', 'update', 'delete']");
});

it('uses the supported cleaning shift value for automatic handover', function (): void {
    $handover = file_get_contents(app_path('Services/OperationalHandoverFlow.php'));

    expect($handover)
        ->not->toContain("'evening'")
        ->toContain("'afternoon'");
});

it('publishes incident reporting in every field role workspace', function (): void {
    $constant = (new ReflectionClass(MobileWorkspaceRegistry::class))
        ->getReflectionConstant('ROLE_MODULES');
    expect($constant)->not->toBeFalse();
    $roleModules = $constant->getValue();

    foreach ([
        UserRole::StafGudang,
        UserRole::KepalaDivisiPersiapan,
        UserRole::PetugasPersiapan,
        UserRole::KepalaDivisiPengolahan,
        UserRole::PetugasPengolahan,
        UserRole::KepalaDivisiPemorsian,
        UserRole::PetugasPemorsian,
        UserRole::KepalaDivisiDistribusi,
        UserRole::PetugasDistribusi,
        UserRole::KepalaDivisiPencucian,
        UserRole::PetugasPencucian,
        UserRole::KepalaDivisiKebersihan,
        UserRole::PetugasKebersihan,
        UserRole::Satpam,
    ] as $role) {
        expect($roleModules[$role->value] ?? [])->toContain('lapangan-insiden');
    }

    expect($roleModules[UserRole::StafGudang->value])
        ->toContain('gudang-retur-pengolahan');
});

it('uses calendar and time pickers instead of manual date input on android', function (): void {
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/OperationalScreens.kt'));

    expect($screen)
        ->toContain('DatePickerDialog(')
        ->toContain('TimePickerDialog(')
        ->toContain('dd-MM-yyyy')
        ->toContain('field.type == "date" || field.type == "datetime"');
});

it('keeps waste handover source choices and warehouse evidence visible on mobile', function (): void {
    $registry = app(MobileWorkspaceRegistry::class);
    $definitions = $registry->definitions();

    $preparationWasteFields = collect($definitions['ba-limbah-persiapan']['fields'])->keyBy('name');
    $warehouseWithdrawalFields = collect($definitions['gudang-pengambilan']['relations']['items']['fields'])->pluck('name');
    $warehouseReturnFields = collect($definitions['gudang-retur']['fields'])->pluck('name');
    $incidentFields = collect($definitions['lapangan-insiden']['fields'])->pluck('name');

    expect($preparationWasteFields['source_id']['options'])
        ->toBe('preparation_sessions')
        ->and($warehouseWithdrawalFields)->toContain('photo_path')
        ->and($warehouseReturnFields)->toContain('photo_path')
        ->and($incidentFields)->toContain('evidence_photo');

    $source = file_get_contents(app_path('Support/Mobile/MobileWorkspaceRegistry.php'));
    expect($source)->toContain("if (\$source === 'preparation_sessions')");
});

it('lets kepala sppg complete final operational approvals from mobile', function (): void {
    $constant = (new ReflectionClass(MobileWorkspaceRegistry::class))
        ->getReflectionConstant('ROLE_MODULES');
    expect($constant)->not->toBeFalse();
    $modules = $constant->getValue()[UserRole::KepalaSppg->value] ?? [];

    expect($modules)->toContain(
        'persiapan',
        'pengolahan',
        'pemorsian',
        'distribusi',
        'pencucian',
        'kebersihan',
        'ba-limbah-persiapan',
        'ba-limbah-pencucian',
        'ba-limbah-kebersihan',
        'lapangan-laporan',
    );

    expect(AccessControl::permissionsForRole(UserRole::KepalaSppg->value))
        ->toContain('preparation.approve')
        ->toContain('processing.approve')
        ->toContain('portioning.approve')
        ->toContain('distribution.approve')
        ->toContain('washing.approve')
        ->toContain('cleaning.approve')
        ->toContain('field_daily_reports.approve');
});

