<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerCollectionItem extends Model
{
    protected $fillable = [
        'container_collection_run_id', 'container_collection_task_id', 'collected_quantity',
        'status', 'collected_by', 'collected_at', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'collected_quantity' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContainerCollectionRun::class, 'container_collection_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContainerCollectionTask::class, 'container_collection_task_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * Field virtual berikut dipakai oleh tampilan Web/Mobile agar riwayat
     * pengambilan dapat dibaca tanpa membuat kolom database duplikat.
     */
    protected function destinationName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->taskRecord()?->destination_name);
    }

    protected function deliveryDate(): Attribute
    {
        return Attribute::get(fn () => $this->taskRecord()?->delivery_date);
    }

    protected function targetContainers(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->taskRecord()
            ? (int) $this->taskRecord()->target_containers
            : null);
    }

    protected function remainingAfterCollection(): Attribute
    {
        return Attribute::get(function (): ?int {
            $task = $this->taskRecord();
            if (! $task) {
                return null;
            }

            $items = $task->relationLoaded('items')
                ? $task->items
                : $task->items()->get();

            $collectedThroughThisItem = (int) $items
                ->filter(fn (self $item): bool => (int) $item->getKey() <= (int) $this->getKey())
                ->sum('collected_quantity');

            return max(0, (int) $task->target_containers - $collectedThroughThisItem);
        });
    }

    protected function collectorName(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->relationLoaded('collector')) {
                return $this->collector?->name;
            }

            return $this->collector()->value('name');
        });
    }

    private function taskRecord(): ?ContainerCollectionTask
    {
        if ($this->relationLoaded('task')) {
            return $this->task;
        }

        return $this->task()->first();
    }
}
