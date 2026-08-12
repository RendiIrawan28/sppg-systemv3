<?php

use App\Enums\OperationalReportStatus;
use App\Enums\UserRole;
use App\Models\PreparationSession;
use App\Support\AccessControl;
use App\Support\Mobile\MobileWorkspaceRegistry;

it('exposes standalone field confirmation and division warehouse withdrawal modules', function (): void {
    $definitions = app(MobileWorkspaceRegistry::class)->definitions();

    expect($definitions)
        ->toHaveKeys([
            'lapangan-konfirmasi',
            'pengambilan-gudang-persiapan',
            'pengambilan-gudang-pengolahan',
            'pengambilan-gudang-pemorsian',
        ])
        ->and($definitions['lapangan-konfirmasi']['allow_create'])->toBeTrue()
        ->and($definitions['pengambilan-gudang-persiapan']['allow_create'])->toBeTrue()
        ->and($definitions['pengambilan-gudang-pengolahan']['allow_create'])->toBeTrue()
        ->and($definitions['pengambilan-gudang-pemorsian']['allow_create'])->toBeTrue();
});

it('allows preparation return submission from the mobile relation form', function (): void {
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['persiapan'];
    $fields = collect($definition['relations']['returns']['fields'])->pluck('name');

    expect($fields)
        ->toContain('preparation_session_item_id')
        ->toContain('requested_quantity')
        ->toContain('condition_status')
        ->toContain('reason')
        ->toContain('photo_path');
});

it('keeps preparation snapshots read only while exposing only operational inputs', function (): void {
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['persiapan'];
    $parentFields = collect($definition['fields'])->keyBy('name');
    $itemFields = collect($definition['relations']['items']['fields'])->keyBy('name');
    $documentationFields = collect($definition['relations']['resultDocumentation']['fields'])->keyBy('name');

    foreach (['preparation_date', 'purpose_reference', 'state', 'status', 'petugas_id', 'started_at', 'completed_at'] as $field) {
        expect($parentFields[$field]['editable'])->toBeFalse();
    }
    foreach (['ingredient_name_snapshot', 'unit_snapshot', 'received_quantity', 'condition_status'] as $field) {
        expect($itemFields[$field]['editable'])->toBeFalse();
    }

    expect($itemFields['processed_quantity']['editable'] ?? true)->toBeTrue()
        ->and($itemFields['waste_quantity']['editable'] ?? true)->toBeTrue()
        ->and($documentationFields['captured_at']['editable'])->toBeFalse();
});

it('locks preparation reports after submission and approval', function (): void {
    $session = new PreparationSession(['status' => OperationalReportStatus::Draft]);
    expect($session->isReportEditable())->toBeTrue();

    $session->status = OperationalReportStatus::RevisionRequired;
    expect($session->isReportEditable())->toBeTrue();

    $session->status = OperationalReportStatus::Submitted;
    expect($session->isReportEditable())->toBeFalse();

    $session->status = OperationalReportStatus::Verified;
    expect($session->isReportEditable())->toBeFalse();
});

it('uses guided dropdowns for preparation output instead of manual name and unit', function (): void {
    $fields = collect(app(MobileWorkspaceRegistry::class)->definitions()['hasil-persiapan']['fields'])
        ->keyBy('name');

    expect($fields['preparation_session_item_id']['type'])->toBe('select')
        ->and($fields['preparation_session_item_id']['options'])->toBe('preparation_session_items')
        ->and($fields['output_name']['editable'])->toBeFalse()
        ->and($fields['unit_snapshot']['editable'])->toBeFalse()
        ->and($fields['target_division']['type'])->toBe('select')
        ->and($fields['storage_location']['type'])->toBe('select');
});

it('allows daily reports to be regenerated from mobile', function (): void {
    $definition = app(MobileWorkspaceRegistry::class)->definitions()['lapangan-laporan'];

    expect($definition['allow_create'])->toBeTrue()
        ->and(collect($definition['fields'])->firstWhere('name', 'report_date')['required'])->toBeTrue();
});

it('grants the field assistant all permissions required by standalone mobile crud', function (): void {
    $permissions = AccessControl::permissionsForRole(UserRole::AsistenLapangan->value);

    expect($permissions)
        ->toContain('daily_beneficiary_confirmations.view')
        ->toContain('daily_beneficiary_confirmations.create')
        ->toContain('daily_beneficiary_confirmations.update')
        ->toContain('daily_beneficiary_confirmations.submit')
        ->toContain('field_daily_reports.create');
});
