<?php

test('manual beneficiary periods can be copied while creating a new period', function (): void {
    $form = file_get_contents(app_path('Livewire/V3/Beneficiaries/Periods/Form.php'));
    $view = file_get_contents(resource_path('views/livewire/v3/beneficiaries/periods/form.blade.php'));
    $service = file_get_contents(app_path('Services/BeneficiaryPeriodSnapshotService.php'));

    expect($form)
        ->toContain('public function copyPreviousPeriod(): void')
        ->toContain("whereHas('categoryTotals')")
        ->toContain("pluck('total_beneficiaries', 'beneficiary_category_id')")
        ->and($view)
        ->toContain('wire:click="copyPreviousPeriod"')
        ->toContain('Salin periode sebelumnya')
        ->and($service)
        ->toContain("'destinations.categoryTotals'")
        ->toContain("'total_beneficiaries' => \$sourceTotal->total_beneficiaries");
});

test('an editable manual period can copy another manual period from its summary', function (): void {
    $component = file_get_contents(app_path('Livewire/V3/Beneficiaries/Periods/Show.php'));
    $view = file_get_contents(resource_path('views/livewire/v3/beneficiaries/periods/show.blade.php'));

    expect($component)
        ->not->toContain('Salin periode tersedia untuk mode data penerima bernama.')
        ->toContain("fn (\$query) => \$query->whereHas('categoryTotals')")
        ->and($view)
        ->toContain('Ganti seluruh isi draft dengan data dari periode yang dipilih?')
        ->toContain('Salin data');
});
