<?php

namespace App\Enums;

enum FieldDailyReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Status Lama: Diajukan',
            self::RevisionRequired => 'Status Lama: Perlu Revisi',
            self::Approved => 'Laporan Otomatis',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::RevisionRequired => 'danger',
            self::Approved => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
