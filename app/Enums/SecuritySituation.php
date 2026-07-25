<?php

namespace App\Enums;

enum SecuritySituation: string
{
    case Safe = 'safe';
    case Attention = 'attention';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Safe => 'Aman',
            self::Attention => 'Perlu Perhatian',
            self::Emergency => 'Darurat',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $situation): array => [$situation->value => $situation->label()])
            ->all();
    }
}
