<?php

namespace App\Console\Commands;

use App\Enums\DistributionRunState;
use App\Enums\FieldDistributionPlanStatus;
use App\Models\FieldDistributionPlan;
use App\Models\User;
use App\Services\FieldOperationalPlanGenerator;
use Illuminate\Console\Command;
use Throwable;

class SyncDistributionRoutes extends Command
{
    protected $signature = 'distribution:sync-routes
        {--plan= : ID rencana distribusi tertentu}
        {--date= : Tanggal distribusi format YYYY-MM-DD}
        {--user= : ID pengguna yang dicatat sebagai pelaksana sinkronisasi}';

    protected $description = 'Membentuk satu perjalanan Distribusi untuk setiap rute pada rencana aktif lama.';

    public function handle(FieldOperationalPlanGenerator $generator): int
    {
        $actorId = (int) $this->option('user');
        $actor = User::query()->whereKey($actorId)->where('is_active', true)->first();

        if (! $actor) {
            $this->error('Opsi --user wajib berisi ID pengguna aktif.');

            return self::FAILURE;
        }

        $query = FieldDistributionPlan::query()
            ->where('status', FieldDistributionPlanStatus::Activated->value)
            ->with(['destinations', 'distributionRuns']);

        if ($planId = (int) $this->option('plan')) {
            $query->whereKey($planId);
        }

        if ($date = trim((string) $this->option('date'))) {
            $query->whereDate('distribution_date', $date);
        }

        $plans = $query->orderBy('distribution_date')->orderBy('id')->get();

        if ($plans->isEmpty()) {
            $this->warn('Tidak ada rencana aktif yang cocok dengan filter.');

            return self::SUCCESS;
        }

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($plans as $plan) {
            $hasRunningRoute = $plan->distributionRuns->contains(
                fn ($run): bool => $run->state !== DistributionRunState::Planned
            );

            if ($hasRunningRoute) {
                $this->warn("Lewati {$plan->plan_number}: sudah ada rute yang dipilih atau berjalan.");
                $skipped++;

                continue;
            }

            try {
                $runs = $generator->generateDistributionRuns($plan, $actor);
                $this->info(sprintf(
                    '%s: %d rute tersinkronisasi (%s).',
                    $plan->plan_number,
                    $runs->count(),
                    $runs->pluck('route_name')->filter()->implode(', '),
                ));
                $synced++;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$plan->plan_number}: {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->line("Berhasil: {$synced}; dilewati: {$skipped}; gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
