<?php

use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\AccessControl;
use App\Enums\UserRole;

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
