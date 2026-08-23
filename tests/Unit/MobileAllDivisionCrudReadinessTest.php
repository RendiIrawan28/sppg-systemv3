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

it('keeps processing identity locked after create and allows manual actual materials on mobile', function (): void {
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['pengolahan'];
    $fields = collect($definition['fields'])->keyBy('name');
    $materialFields = collect($definition['relations']['materialUsages']['fields'])->keyBy('name');
    $documentationFields = collect($definition['relations']['documentations']['fields'])->keyBy('name');

    expect($fields['production_date']['create_only'])->toBeTrue()
        ->and($fields['product_name']['create_only'])->toBeTrue()
        ->and($fields['menu_name_snapshot']['editable'])->toBeFalse()
        ->and($fields['target_output_quantity']['editable'])->toBeFalse()
        ->and($fields['target_output_unit']['editable'])->toBeFalse()
        ->and($fields['petugas_id']['editable'])->toBeFalse()
        ->and($fields['notes']['editable'] ?? true)->toBeTrue();

    expect($materialFields['material_name']['editable'])->toBeTrue()
        ->and($materialFields['quantity']['editable'])->toBeTrue()
        ->and($materialFields['unit_name']['editable'])->toBeTrue()
        ->and($materialFields['source_reference']['editable'])->toBeFalse();

    expect($documentationFields['output_unit']['type'])->toBe('select')
        ->and($documentationFields['output_unit']['options'])->toBe('processing_output_units');
});

it('keeps portioning identity and material sources automatic on mobile', function (): void {
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['pemorsian'];
    $fields = collect($definition['fields'])->keyBy('name');

    expect($fields)->toHaveKey('field_distribution_plan_id')
        ->and($fields['portioning_date']['editable'])->toBeFalse()
        ->and($fields['menu_name_snapshot']['editable'])->toBeFalse()
        ->and($fields['target_small_portions']['editable'])->toBeFalse()
        ->and($fields['target_large_portions']['editable'])->toBeFalse()
        ->and($definition['relations'])->toHaveKey('preparationOutputWithdrawals');

    foreach ($definition['relations']['supplies']['fields'] as $field) {
        expect($field['editable'])->toBeFalse();
    }
});

it('offers both processing reports on android', function (): void {
    $screen = file_get_contents(base_path('android/app/src/main/java/id/sppg/mobile/ui/OperationalScreens.kt'));
    $documentController = file_get_contents(app_path('Http/Controllers/Api/MobileDocumentController.php'));

    expect($screen)
        ->toContain('EKSPOR LAPORAN')
        ->toContain('Pemantauan suhu')
        ->toContain('onOpenDocument("temperature")')
        ->and($documentController)
        ->toContain("query('type') === 'temperature'")
        ->toContain('->temperature($request, $item)');
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
        ->toContain("'incidents' => \$parent?->isReportEditable()")
        ->toContain("'mobile/distribusi/stops'");
});

it('uses the supported cleaning shift value for automatic handover', function (): void {
    $handover = file_get_contents(app_path('Services/OperationalHandoverFlow.php'));

    expect($handover)
        ->not->toContain("'evening'")
        ->toContain("'afternoon'");
});

it('publishes incident reporting for field divisions and limits the warehouse mobile workspace', function (): void {
    $constant = (new ReflectionClass(MobileWorkspaceRegistry::class))
        ->getReflectionConstant('ROLE_MODULES');
    expect($constant)->not->toBeFalse();
    $roleModules = $constant->getValue();
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    foreach ([
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
        ->toBe([
            'gudang', 'gudang-non-pangan', 'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'gudang-retur',
            'gudang-stok', 'gudang-stok-non-pangan', 'gudang-stok-awal',
            'gudang-stok-awal-non-pangan', 'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan',
        ])
        ->not->toContain('gudang-retur-pengolahan', 'lapangan-insiden');

    expect($definitions['gudang-stok-awal']['allow_create'])->toBeTrue()
        ->and(collect($definitions['gudang-stok-awal']['fields'])->pluck('name'))
        ->toContain('rows_payload', 'photo_path');
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
        ->and($definitions['ba-limbah-persiapan']['allow_create'])->toBeFalse()
        ->and($definitions['ba-limbah-persiapan']['allow_delete'])->toBeFalse()
        ->and($preparationWasteFields['source_id']['editable'])->toBeFalse()
        ->and($preparationWasteFields['first_party_name']['editable'] ?? true)->toBeTrue()
        ->and($warehouseWithdrawalFields)->toContain('photo_path')
        ->and($warehouseReturnFields)->toContain('photo_path')
        ->and($incidentFields)->toContain('evidence_photo');

    $source = file_get_contents(app_path('Support/Mobile/MobileWorkspaceRegistry.php'));
    expect($source)->toContain("if (\$source === 'preparation_sessions')");

    $constant = (new ReflectionClass(MobileWorkspaceRegistry::class))->getReflectionConstant('ROLE_MODULES');
    $roleModules = $constant->getValue();
    expect($roleModules[UserRole::KepalaDivisiPersiapan->value])->toContain('ba-limbah-persiapan')
        ->and($roleModules[UserRole::PetugasPersiapan->value])->toContain('ba-limbah-persiapan');
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
