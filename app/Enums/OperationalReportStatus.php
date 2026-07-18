<?php

namespace App\Enums;

enum OperationalReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case DivisionApproved = 'division_approved';
    case RevisionRequired = 'revision_required';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Menunggu Kepala Divisi',
            self::DivisionApproved => 'Menunggu Kepala SPPG',
            self::RevisionRequired => 'Perlu Revisi',
            self::Verified => 'Disetujui Kepala SPPG',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::DivisionApproved => 'info',
            self::RevisionRequired => 'danger',
            self::Verified => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [
                $status->value => $status->label(),
            ])
            ->all();
    }
}
