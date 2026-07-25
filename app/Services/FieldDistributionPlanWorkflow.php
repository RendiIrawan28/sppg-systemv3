<?php

namespace App\Services;

use App\Enums\FieldDistributionPlanStatus;
use App\Models\FieldDistributionPlan;
use App\Models\User;
use App\Support\V3\SystemUnit;
use DomainException;
use Illuminate\Support\Facades\DB;

class FieldDistributionPlanWorkflow
{
    public function submissionIssues(FieldDistributionPlan $plan): array
    {
        $plan->recalculateTotals();
        $plan->load('destinations.recipientGroups');
        $issues = [];

        if (blank($plan->menu_cycle_day_id) || blank($plan->menu_id)) {
            $issues[] = 'Menu harus dipilih dari Siklus Menu yang sudah disetujui atau aktif.';
        }

        if (blank($plan->menu_name_snapshot)) {
            $issues[] = 'Menu atau nama menu belum diisi.';
        }

        if (blank($plan->service_date) || blank($plan->production_date)) {
            $issues[] = 'Tanggal layanan dan tanggal produksi dari siklus menu belum valid.';
        }

        if ($plan->destinations->isEmpty()) {
            $issues[] = 'Minimal satu sekolah atau Posyandu wajib ditambahkan.';
        }

        foreach ($plan->destinations as $index => $destination) {
            $label = $destination->destination_name_snapshot ?: 'Tujuan '.($index + 1);

            if ($destination->recipientGroups->isEmpty()) {
                $issues[] = "{$label}: rincian kelompok penerima belum diisi.";
            }

            foreach ($destination->recipientGroups as $group) {
                $groupLabel = $group->beneficiary_category_name_snapshot ?: 'Kelompok penerima';

                if ((int) $group->confirmed_beneficiaries <= 0) {
                    $issues[] = "{$label} - {$groupLabel}: jumlah aktual harus lebih dari nol.";
                }

                if (blank($group->menu_audience)) {
                    $issues[] = "{$label} - {$groupLabel}: kelompok menu belum diisi.";
                }

                if (! in_array($group->portion_size, ['small', 'large'], true)) {
                    $issues[] = "{$label} - {$groupLabel}: kategori porsi belum valid.";
                }
            }

            if ((int) $destination->confirmed_beneficiaries <= 0) {
                $issues[] = "{$label}: jumlah penerima terkonfirmasi harus lebih dari nol.";
            }

            if ((int) $destination->total_portions <= 0) {
                $issues[] = "{$label}: jumlah porsi harus lebih dari nol.";
            }

            if ((int) $destination->total_portions !== (int) $destination->confirmed_beneficiaries) {
                $issues[] = "{$label}: total porsi harus sama dengan penerima terkonfirmasi.";
            }

            if (blank($destination->route_name)) {
                $issues[] = "{$label}: rute distribusi belum diisi.";
            }

            if (! in_array($destination->confirmation_status, ['confirmed', 'changed'], true)) {
                $issues[] = "{$label}: konfirmasi penerima belum selesai.";
            }

            if ($destination->confirmation_status === 'changed' && blank($destination->change_reason)) {
                $issues[] = "{$label}: alasan perubahan jumlah penerima wajib diisi.";
            }

            if (in_array($destination->confirmation_status, ['confirmed', 'changed'], true)
                && ! $destination->confirmed_at) {
                $issues[] = "{$label}: waktu konfirmasi belum diisi.";
            }
        }

        if ($plan->planned_total_portions !== $plan->confirmed_beneficiaries) {
            $issues[] = 'Total seluruh porsi tidak sama dengan total penerima terkonfirmasi.';
        }

        if ($plan->distribution_date && now()->startOfDay()->gt($plan->distribution_date->copy()->subDays(2)->startOfDay())) {
            $issues[] = 'Rencana tidak dapat diajukan karena sudah melewati batas revisi maksimal H-2.';
        }

        return array_values(array_unique($issues));
    }

    public function submit(FieldDistributionPlan $plan, User $actor, ?string $notes = null): array
    {
        if (! $actor->can('field_planning.submit')) {
            throw new DomainException('Anda tidak memiliki izin untuk mengaktifkan rencana distribusi.');
        }

        if (! app(SystemUnit::class)->owns($plan)) {
            throw new DomainException('Rencana distribusi bukan milik Unit SPPG aktif.');
        }

        $issues = $this->submissionIssues($plan);

        if ($issues !== []) {
            throw new DomainException(implode("\n", $issues));
        }

        if (! $plan->isEditable()) {
            throw new DomainException('Rencana tidak berada pada status yang dapat diajukan.');
        }

        return DB::transaction(function () use ($plan, $actor, $notes): array {
            $this->transitionInsideTransaction(
                $plan,
                FieldDistributionPlanStatus::Activated,
                $actor,
                $notes ?: 'Rencana diaktifkan',
                [
                    'submitted_by' => $actor->getKey(),
                    'submitted_at' => now(),
                    'activated_by' => $actor->getKey(),
                    'activated_at' => now(),
                    'approved_by' => null,
                    'approved_at' => null,
                    'review_notes' => null,
                ]
            );

            return ['operational_documents' => 'manual'];
        });
    }

    public function synchronize(FieldDistributionPlan $plan, User $actor): array
    {
        throw new DomainException('Pembuatan dan sinkronisasi pekerjaan divisi otomatis sedang dinonaktifkan. Dokumen dibuat manual pada masing-masing modul.');
    }

    public function complete(FieldDistributionPlan $plan, User $actor, ?string $notes = null): void
    {
        if ($plan->status !== FieldDistributionPlanStatus::Activated) {
            throw new DomainException('Hanya rencana yang sudah diproses divisi yang dapat diselesaikan.');
        }

        DB::transaction(function () use ($plan, $actor, $notes): void {
            $this->transitionInsideTransaction(
                $plan,
                FieldDistributionPlanStatus::Completed,
                $actor,
                $notes,
                ['completed_at' => now()],
            );

            app(FieldDailyReportGenerator::class)->generateAutomatic(
                (int) $plan->sppg_unit_id,
                $plan->distribution_date->toDateString(),
                $actor,
            );
        });
    }

    private function transition(
        FieldDistributionPlan $plan,
        FieldDistributionPlanStatus $to,
        User $actor,
        ?string $notes,
        array $attributes = [],
    ): void {
        DB::transaction(fn () => $this->transitionInsideTransaction($plan, $to, $actor, $notes, $attributes));
    }

    private function transitionInsideTransaction(
        FieldDistributionPlan $plan,
        FieldDistributionPlanStatus $to,
        User $actor,
        ?string $notes,
        array $attributes = [],
    ): void {
        $plan->refresh();
        $from = $plan->status?->value;

        $plan->forceFill(array_merge($attributes, [
            'status' => $to,
            'updated_by' => $actor->getKey(),
        ]))->save();

        $plan->histories()->create([
            'from_status' => $from,
            'to_status' => $to->value,
            'actor_id' => $actor->getKey(),
            'actor_name_snapshot' => $actor->name,
            'notes' => $notes,
            'snapshot' => $this->snapshot($plan),
        ]);
    }

    private function snapshot(FieldDistributionPlan $plan): array
    {
        $plan->load('destinations.recipientGroups');

        return [
            'plan_number' => $plan->plan_number,
            'distribution_date' => $plan->distribution_date?->toDateString(),
            'menu_name' => $plan->menu_name_snapshot,
            'confirmed_beneficiaries' => $plan->confirmed_beneficiaries,
            'planned_total_portions' => $plan->planned_total_portions,
            'destination_count' => $plan->destination_count,
            'destinations' => $plan->destinations->map(fn ($destination): array => [
                'name' => $destination->destination_name_snapshot,
                'confirmed' => $destination->confirmed_beneficiaries,
                'small' => $destination->small_portions,
                'large' => $destination->large_portions,
                'route' => $destination->route_name,
                'recipient_groups' => $destination->recipientGroups->map(fn ($group): array => [
                    'name' => $group->beneficiary_category_name_snapshot,
                    'menu_audience' => $group->menu_audience,
                    'portion_size' => $group->portion_size,
                    'registered' => $group->registered_beneficiaries,
                    'confirmed' => $group->confirmed_beneficiaries,
                    'small' => $group->small_portions,
                    'large' => $group->large_portions,
                ])->all(),
            ])->all(),
        ];
    }
}
