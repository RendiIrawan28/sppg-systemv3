<?php

namespace App\Enums;

enum DistributionStopStatus: string
{
    case Planned = 'planned';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::InTransit => 'Dalam Perjalanan',
            self::Delivered => 'Terkirim',
            self::Partial => 'Terkirim Sebagian',
            self::Failed => 'Gagal Dikirim',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InTransit => 'warning',
            self::Delivered => 'success',
            self::Partial => 'info',
            self::Failed => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Partial, self::Failed], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
