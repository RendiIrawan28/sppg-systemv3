<?php

namespace App\Services;

use App\Models\BeneficiaryPeriod;
use App\Models\SppgUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class BeneficiaryPeriodWorkflowService
{
    public function submit(BeneficiaryPeriod $period, User $actor, ?string $notes = null): void
    {
        $this->authorize($actor, 'beneficiary_periods.submit');
        $this->assertCanSubmit($period);
        $this->transition($period, $actor, 'submitted', 'submit', $notes, [
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
        ]);
    }

    public function requestRevision(BeneficiaryPeriod $period, User $actor, string $notes): void
    {
        $this->authorize($actor, 'beneficiary_periods.approve');

        if ($period->status !== 'submitted') {
            throw new DomainException('Hanya periode yang sedang diajukan yang dapat dikembalikan untuk revisi.');
        }

        $this->transition($period, $actor, 'revision_required', 'request_revision', $notes, [
            'revision_number' => ((int) $period->revision_number) + 1,
        ]);
    }

    public function approve(BeneficiaryPeriod $period, User $actor, ?string $notes = null): void
    {
        $this->authorize($actor, 'beneficiary_periods.approve');

        if ($period->status !== 'submitted') {
            throw new DomainException('Periode harus berstatus Diajukan sebelum disetujui.');
        }

        if ((int) $period->submitted_by === (int) $actor->getKey()) {
            throw new DomainException('Pengaju tidak boleh menyetujui master periode yang diajukannya sendiri.');
        }

        $this->assertDates($period);
        $this->assertSnapshotReady($period);
        $documentNumber = $period->document_number ?: $this->documentNumber($period);

        $this->transition($period, $actor, 'approved', 'approve', $notes, [
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'locked_at' => now(),
            'document_number' => $documentNumber,
        ]);
    }

    public function activate(BeneficiaryPeriod $period, User $actor, ?string $notes = null): void
    {
        $this->authorize($actor, 'beneficiary_periods.activate');

        if ($period->status !== 'approved') {
            throw new DomainException('Hanya periode yang sudah disetujui yang dapat diaktifkan.');
        }

        DB::transaction(function () use ($period, $actor, $notes): void {
            BeneficiaryPeriod::query()
                ->where('sppg_unit_id', $period->sppg_unit_id)
                ->where('status', 'active')
                ->whereKeyNot($period->getKey())
                ->get()
                ->each(function (BeneficiaryPeriod $active) use ($actor): void {
                    $this->transition($active, $actor, 'closed', 'auto_close', 'Ditutup otomatis saat periode baru diaktifkan.', [
                        'closed_at' => now(),
                    ]);
                });

            $this->transition($period, $actor, 'active', 'activate', $notes);
        });
    }

    public function close(BeneficiaryPeriod $period, User $actor, ?string $notes = null): void
    {
        $this->authorize($actor, 'beneficiary_periods.close');

        if (! in_array($period->status, ['approved', 'active'], true)) {
            throw new DomainException('Periode ini tidak dapat ditutup.');
        }

        $this->transition($period, $actor, 'closed', 'close', $notes, [
            'closed_at' => now(),
        ]);
    }

    public function assertDates(BeneficiaryPeriod $period): void
    {
        $start = CarbonImmutable::parse($period->start_date)->startOfDay();
        $end = CarbonImmutable::parse($period->end_date)->startOfDay();

        $expectedEnd = $start->addDays(13);

        if (! $end->equalTo($expectedEnd)) {
            throw new DomainException(
                'Periode penerima wajib tepat 14 hari, termasuk tanggal mulai dan selesai. '
                    . 'Tanggal selesai yang seharusnya: '
                    . $expectedEnd->format('d-m-Y')
                    . '.'
            );
        }

        $overlap = BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $period->sppg_unit_id)
            ->whereKeyNot($period->getKey())
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function ($query) use ($start, $end): void {
                        $query->whereDate('start_date', '<=', $start->toDateString())
                            ->whereDate('end_date', '>=', $end->toDateString());
                    });
            })
            ->exists();

        if ($overlap) {
            throw new DomainException('Rentang tanggal bertumpang tindih dengan periode penerima lain pada Unit SPPG ini.');
        }
    }

    public function assertSnapshotReady(BeneficiaryPeriod $period): void
    {
        app(BeneficiaryPeriodSnapshotService::class)->recalculate($period);
        $period->refresh();

        if ($period->destination_count < 1 || $period->active_members < 1) {
            throw new DomainException('Periode belum memiliki instansi dan penerima aktif.');
        }

        $missingCategory = $period->members()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('beneficiary_category_code_snapshot')
                    ->orWhereNull('portion_category')
                    ->orWhereNull('menu_audience');
            })
            ->exists();

        if ($missingCategory) {
            throw new DomainException('Masih ada penerima aktif yang belum mempunyai kelompok penerima, kategori porsi, atau kelompok menu.');
        }
    }

    private function assertCanSubmit(BeneficiaryPeriod $period): void
    {
        if (! in_array($period->status, ['draft', 'revision_required'], true)) {
            throw new DomainException('Hanya periode Draft atau Perlu Revisi yang dapat diajukan.');
        }

        $this->assertDates($period);
        $this->assertSnapshotReady($period);
    }

    private function transition(
        BeneficiaryPeriod $period,
        User $actor,
        string $toStatus,
        string $action,
        ?string $notes = null,
        array $attributes = [],
    ): void {
        $fromStatus = $period->status;

        DB::transaction(function () use ($period, $actor, $toStatus, $action, $notes, $attributes, $fromStatus): void {
            $period->forceFill($attributes + ['status' => $toStatus])->save();
            $period->histories()->create([
                'user_id' => $actor->getKey(),
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
            ]);
        });
    }


    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new DomainException('Anda tidak memiliki izin untuk menjalankan proses ini.');
        }
    }

    private function documentNumber(BeneficiaryPeriod $period): string
    {
        $unitCode = SppgUnit::query()->whereKey($period->sppg_unit_id)->value('code') ?: 'SPPG';

        return sprintf(
            'MP/%s/%s/%03d',
            strtoupper((string) $unitCode),
            CarbonImmutable::parse($period->start_date)->format('Ym'),
            $period->getKey(),
        );
    }
}
