<?php

namespace App\Enums;

enum HeadApprovalTaskStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case RevisionRequired = 'revision_required';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Persetujuan',
            self::Approved => 'Disetujui',
            self::RevisionRequired => 'Perlu Revisi',
            self::Skipped => 'Tidak Aktif',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::RevisionRequired => 'danger',
            self::Skipped => 'gray',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
