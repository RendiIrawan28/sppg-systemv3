<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileNotification;
use App\Models\MobileTask;
use App\Models\SecurityShift;
use App\Services\Mobile\MobileTaskService;
use App\Support\V3\SystemUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTaskController extends Controller
{
    public function index(Request $request, SystemUnit $systemUnit, MobileTaskService $service): JsonResponse
    {
        SecurityShift::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->where('officer_id', $request->user()->getKey())
            ->active()
            ->with('reports')
            ->get()
            ->each(fn (SecurityShift $shift) => $service->syncSecurityShiftTasks($shift));

        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,completed,cancelled,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'pending';
        $query = MobileTask::query()
            ->where('user_id', $request->user()->getKey())
            ->where('sppg_unit_id', $systemUnit->id());
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        $tasks = $query
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($tasks->items())->map(fn (MobileTask $task): array => $this->taskPayload($task)),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'total' => $tasks->total(),
                'pending_count' => MobileTask::query()
                    ->where('user_id', $request->user()->getKey())
                    ->where('sppg_unit_id', $systemUnit->id())
                    ->pending()->count(),
                'unread_notification_count' => MobileNotification::query()
                    ->where('user_id', $request->user()->getKey())
                    ->whereNull('read_at')->count(),
            ],
        ]);
    }

    private function taskPayload(MobileTask $task): array
    {
        return [
            'id' => $task->getKey(),
            'uuid' => $task->uuid,
            'type' => $task->task_type,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'status' => $task->status,
            'screen' => $task->screen,
            'payload' => $task->payload ?? [],
            'due_at' => $task->due_at?->toIso8601String(),
            'is_overdue' => $task->status === 'pending' && $task->due_at?->isPast(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
