<?php

namespace App\Enums;

enum NutritionRecordStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Menunggu Persetujuan',
            self::RevisionRequired => 'Perlu Revisi',
            self::Approved => 'Disetujui',
            self::Active => 'Aktif',
            self::Archived => 'Diarsipkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::RevisionRequired => 'danger',
            self::Approved => 'success',
            self::Active => 'primary',
            self::Archived => 'gray',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::RevisionRequired,
        ], true);
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
