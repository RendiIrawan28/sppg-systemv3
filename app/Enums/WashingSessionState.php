<?php

namespace App\Enums;

enum WashingSessionState: string
{
    case Planned = 'planned';
    case Received = 'received';
    case Washing = 'washing';
    case Completed = 'completed';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Menunggu Diterima',
            self::Received => 'Ompreng Diterima',
            self::Washing => 'Sedang Dicuci',
            self::Completed => 'Pencucian Selesai',
            self::Ready => 'Siap Digunakan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Received => 'info',
            self::Washing => 'warning',
            self::Completed => 'primary',
            self::Ready => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }
}
