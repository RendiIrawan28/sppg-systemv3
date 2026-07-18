<?php

namespace App\Enums;

enum MenuStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';
    case InUse = 'in_use';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Menunggu Persetujuan Kepala SPPG',
            self::RevisionRequired => 'Perlu Revisi',
            self::Approved => 'Disetujui',
            self::InUse => 'Aktif / Digunakan',
            self::Archived => 'Diarsipkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'warning',
            self::RevisionRequired => 'danger',
            self::Approved => 'success',
            self::InUse => 'info',
            self::Archived => 'gray',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
