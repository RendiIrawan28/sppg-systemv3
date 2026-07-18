<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class DailyBeneficiaryConfirmationRules
{
    public static function reasonIsRequired(int $masterCount, int $actualCount): bool
    {
        return $masterCount !== $actualCount;
    }

    public static function revisionIsAllowed(
        CarbonInterface|string|null $serviceDate,
        CarbonInterface|string|null $referenceDate = null,
    ): bool {
        if ($serviceDate === null) {
            return false;
        }

        $service = CarbonImmutable::parse($serviceDate)->startOfDay();
        $reference = $referenceDate === null
            ? CarbonImmutable::today(config('app.timezone'))
            : CarbonImmutable::parse($referenceDate)->startOfDay();

        // Revisi masih diperbolehkan pada H-2, tetapi ditutup mulai H-1.
        return $service->greaterThanOrEqualTo($reference->addDays(2));
    }
}
