<?php

namespace App\Services;

use App\Enums\OperationalReportStatus;
use App\Models\User;
use BackedEnum;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Applies the existing per-report workflow to every eligible report in one date-scoped action. */
class BulkOperationalReportReviewService
{
    public function stageFor(User $actor): OperationalReportStatus
    {
        return app(OperationalReportApprovalService::class)->isHeadSppg($actor)
            ? OperationalReportStatus::DivisionApproved
            : OperationalReportStatus::Submitted;
    }

    /** @param string|array<int, string> $permissions */
    public function pendingCount(Builder $scope, User $actor, string|array $permissions, ?string $status = null): int
    {
        if (! $this->hasPermission($actor, $permissions)) {
            return 0;
        }

        return (clone $scope)->where('status', $status ?? $this->stageFor($actor)->value)->count();
    }

    /**
     * @param  string|array<int, string>  $permissions
     * @param  Closure(Model, User): mixed  $review
     */
    public function review(Builder $scope, User $actor, string|array $permissions, Closure $review, ?string $status = null): int
    {
        abort_unless($this->hasPermission($actor, $permissions), 403);
        $expectedStatus = $status ?? $this->stageFor($actor)->value;
        $pending = (clone $scope)->where('status', $expectedStatus)->orderBy('id')->limit(501)->pluck('id');
        if ($pending->isEmpty()) {
            throw ValidationException::withMessages(['bulkReview' => 'Tidak ada laporan pada tahap verifikasi ini untuk tanggal yang dipilih.']);
        }
        if ($pending->count() > 500) {
            throw ValidationException::withMessages(['bulkReview' => 'Terdapat lebih dari 500 laporan. Hubungi admin untuk memprosesnya bertahap.']);
        }

        return DB::transaction(function () use ($scope, $actor, $review, $expectedStatus, $pending): int {
            foreach ($pending as $id) {
                $record = (clone $scope)->find($id);
                if (! $record) {
                    throw ValidationException::withMessages(['bulkReview' => 'Daftar laporan berubah. Muat ulang halaman lalu coba lagi.']);
                }

                $currentStatus = $record->status instanceof BackedEnum
                    ? $record->status->value : (string) $record->status;
                // Some workflows already review an entire daily group; do not review it twice.
                if ($currentStatus !== $expectedStatus) {
                    continue;
                }

                try {
                    $review($record, $actor);
                } catch (ValidationException $exception) {
                    $number = $record->batch_number ?? $record->session_number ?? $record->run_number ?? $record->report_number ?? '#'.$id;
                    throw ValidationException::withMessages([
                        'bulkReview' => "Laporan {$number}: ".collect($exception->errors())->flatten()->first(),
                    ]);
                } catch (DomainException $exception) {
                    $number = $record->batch_number ?? $record->session_number ?? $record->run_number ?? $record->report_number ?? '#'.$id;
                    throw ValidationException::withMessages(['bulkReview' => "Laporan {$number}: {$exception->getMessage()}"]);
                }
            }

            return $pending->count() - (clone $scope)->whereKey($pending->all())
                ->where('status', $expectedStatus)->count();
        });
    }

    /** @param string|array<int, string> $permissions */
    private function hasPermission(User $actor, string|array $permissions): bool
    {
        foreach ((array) $permissions as $permission) {
            if ($actor->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
