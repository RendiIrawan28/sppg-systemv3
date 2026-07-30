<?php

namespace App\Http\Controllers\Api;

use App\Enums\SecurityShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\MobileTask;
use App\Models\SecurityShift;
use App\Services\Mobile\MobileTaskService;
use App\Services\SecurityMonitoringService;
use App\Support\V3\SystemUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MobileSecurityController extends Controller
{
    public function overview(Request $request, SystemUnit $systemUnit, MobileTaskService $tasks): JsonResponse
    {
        abort_unless($request->user()->can('security.view'), 403);
        $active = SecurityShift::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->where('officer_id', $request->user()->getKey())
            ->active()
            ->with('reports')
            ->latest('started_at')
            ->first();
        if ($active) {
            $tasks->syncSecurityShiftTasks($active);
        }

        $recent = SecurityShift::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->where('officer_id', $request->user()->getKey())
            ->withCount('reports')
            ->latest('started_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'active_shift' => $active ? $this->shiftPayload($active) : null,
                'recent_shifts' => $recent->map(fn (SecurityShift $shift): array => [
                    'id' => $shift->getKey(),
                    'started_at' => $shift->started_at?->toIso8601String(),
                    'completed_at' => $shift->completed_at?->toIso8601String(),
                    'status' => $shift->status->value,
                    'reports_count' => (int) $shift->reports_count,
                    'reports_expected' => (int) $shift->reports_expected,
                ]),
                'pending_tasks' => MobileTask::query()
                    ->where('user_id', $request->user()->getKey())
                    ->where('sppg_unit_id', $systemUnit->id())
                    ->where('task_type', 'security_periodic_report')
                    ->pending()
                    ->orderBy('due_at')
                    ->get()
                    ->map(fn (MobileTask $task): array => [
                        'id' => $task->getKey(),
                        'sequence_number' => $task->sequence_number,
                        'title' => $task->title,
                        'due_at' => $task->due_at?->toIso8601String(),
                        'is_overdue' => $task->due_at?->isPast() ?? false,
                    ]),
                'can_start_shift' => $request->user()->can('security.create') && ! $active,
            ],
        ]);
    }

    public function start(Request $request, SystemUnit $systemUnit): JsonResponse
    {
        $shift = app(SecurityMonitoringService::class)->startShift($systemUnit->get(), $request->user());
        $shift->load('reports');

        return response()->json([
            'message' => 'Shift keamanan 12 jam berhasil dimulai.',
            'data' => $this->shiftPayload($shift),
        ], 201);
    }

    public function report(Request $request, SecurityShift $shift, SystemUnit $systemUnit): JsonResponse
    {
        abort_unless((int) $shift->sppg_unit_id === (int) $systemUnit->id(), 404);
        abort_unless(
            $request->user()->is_super_admin || (int) $shift->officer_id === (int) $request->user()->getKey(),
            403,
        );

        $data = $request->validate([
            'situation' => ['required', 'in:safe,attention,emergency'],
            'gate_secure' => ['required', 'boolean'],
            'perimeter_secure' => ['required', 'boolean'],
            'access_activity' => ['nullable', 'string', 'max:5000'],
            'visitor_activity' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'photo' => ['required', 'string', 'max:7500000'],
        ]);
        $path = $this->storeEncodedImage($data['photo']);

        try {
            $report = app(SecurityMonitoringService::class)->submitReport(
                $shift,
                $request->user(),
                [
                    'situation' => $data['situation'],
                    'gate_secure' => $data['gate_secure'],
                    'perimeter_secure' => $data['perimeter_secure'],
                    'access_activity' => trim((string) ($data['access_activity'] ?? '')) ?: null,
                    'visitor_activity' => trim((string) ($data['visitor_activity'] ?? '')) ?: null,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'photo_path' => $path,
                ],
            );
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        $shift->refresh()->load('reports');

        return response()->json([
            'message' => "Laporan keamanan ke-{$report->sequence_number} berhasil disimpan.",
            'data' => $this->shiftPayload($shift),
        ], 201);
    }

    private function shiftPayload(SecurityShift $shift): array
    {
        $shift->loadMissing('reports');

        return [
            'id' => $shift->getKey(),
            'uuid' => $shift->uuid,
            'officer_name' => $shift->officer_name_snapshot,
            'started_at' => $shift->started_at?->toIso8601String(),
            'scheduled_end_at' => $shift->scheduled_end_at?->toIso8601String(),
            'completed_at' => $shift->completed_at?->toIso8601String(),
            'status' => $shift->status instanceof SecurityShiftStatus ? $shift->status->value : (string) $shift->status,
            'reports_expected' => (int) $shift->reports_expected,
            'reports_count' => $shift->reports->count(),
            'next_report_sequence' => $shift->next_report_sequence,
            'next_report_due_at' => $shift->next_report_due_at?->toIso8601String(),
            'report_due' => $shift->isReportDue(),
            'reports' => $shift->reports->map(fn ($report): array => [
                'id' => $report->getKey(),
                'sequence_number' => $report->sequence_number,
                'due_at' => $report->due_at?->toIso8601String(),
                'reported_at' => $report->reported_at?->toIso8601String(),
                'situation' => $report->situation->value,
                'gate_secure' => (bool) $report->gate_secure,
                'perimeter_secure' => (bool) $report->perimeter_secure,
                'access_activity' => $report->access_activity,
                'visitor_activity' => $report->visitor_activity,
                'notes' => $report->notes,
                'photo_url' => $report->photo_path ? Storage::disk('public')->url($report->photo_path) : null,
            ]),
        ];
    }

    private function storeEncodedImage(string $encoded): string
    {
        if (! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/s', $encoded, $matches)) {
            throw ValidationException::withMessages(['photo' => 'Format foto tidak didukung.']);
        }
        $contents = base64_decode($matches[2], true);
        if ($contents === false || strlen($contents) > 5 * 1024 * 1024 || @getimagesizefromstring($contents) === false) {
            throw ValidationException::withMessages(['photo' => 'Foto tidak valid atau ukurannya melebihi 5 MB.']);
        }
        $extension = match ($matches[1]) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = 'mobile/keamanan/reports/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
