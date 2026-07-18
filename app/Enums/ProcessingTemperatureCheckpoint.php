<?php

namespace App\Enums;

enum ProcessingTemperatureCheckpoint: string
{
    case Initial = 'initial';
    case DuringProcess = 'during_process';
    case Final = 'final';
    case Holding = 'holding';
    case BeforeHandover = 'before_handover';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Suhu Awal',
            self::DuringProcess => 'Saat Proses',
            self::Final => 'Suhu Akhir',
            self::Holding => 'Penyimpanan Sementara',
            self::BeforeHandover => 'Sebelum Pemorsian',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $checkpoint): array => [
                $checkpoint->value => $checkpoint->label(),
            ])
            ->all();
    }
}
