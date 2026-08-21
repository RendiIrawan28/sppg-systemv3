<?php

namespace App\Livewire\V3\Administration;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\TestDataCleanupLog;
use App\Services\TestDataCleanupService;
use App\Support\V3\TestDataCleanupRegistry;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Throwable;

class TestDataCleanup extends Component
{
    use InteractsWithV3Shell;

    public string $recordType = 'beneficiary-periods';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $search = '';

    public ?int $selectedId = null;

    public string $confirmation = '';

    public string $reason = '';

    public ?string $actionMessage = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->dateFrom = today()->subDays(30)->toDateString();
        $this->dateTo = today()->toDateString();
    }

    public function updatedRecordType(): void
    {
        $this->cancelDelete();
    }

    public function selectForDelete(int $recordId, TestDataCleanupRegistry $registry): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $record = $this->findRecord($registry->get($this->recordType), $recordId);
        abort_unless($record, 404);

        $this->selectedId = $recordId;
        $this->confirmation = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancelDelete(): void
    {
        $this->selectedId = null;
        $this->confirmation = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function purge(TestDataCleanupRegistry $registry, TestDataCleanupService $cleanup): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->validate([
            'selectedId' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'in:HAPUS DATA'],
        ], [
            'reason.min' => 'Alasan pembersihan minimal 10 karakter.',
            'confirmation.in' => 'Ketik HAPUS DATA dengan tepat untuk melanjutkan.',
        ]);

        $definition = $registry->get($this->recordType);
        abort_unless($this->findRecord($definition, (int) $this->selectedId), 404);

        try {
            $counts = $cleanup->purge(
                $this->recordType,
                (int) $this->selectedId,
                $this->currentUnit()->getKey(),
                auth()->user(),
                $this->reason,
            );
            $total = array_sum($counts);
            $this->actionMessage = "Pembersihan selesai. {$total} baris data terkait telah dihapus.";
            $this->cancelDelete();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('cleanup', 'Data belum dapat dibersihkan karena masih memiliki keterkaitan yang tidak aman. Periksa urutan data terkait lalu coba kembali.');
        }
    }

    public function render(TestDataCleanupRegistry $registry)
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $unit = $this->currentUnit();
        $definitions = $registry->definitions();
        $definition = $registry->get($this->recordType);
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass;
        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }
        if (Schema::hasColumn($model->getTable(), 'sppg_unit_id')) {
            $query->where('sppg_unit_id', $unit->getKey());
        }
        if ($this->dateFrom !== '') {
            $query->whereDate($definition['date'], '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $query->whereDate($definition['date'], '<=', $this->dateTo);
        }
        if (trim($this->search) !== '') {
            $search = trim($this->search);
            $query->where(function ($query) use ($definition, $search): void {
                $query->whereKey(is_numeric($search) ? (int) $search : -1)
                    ->orWhere($definition['number'], 'like', "%{$search}%");
                if ($definition['title'] !== $definition['number']) {
                    $query->orWhere($definition['title'], 'like', "%{$search}%");
                }
            });
        }

        $records = $query->latest($definition['date'])->latest('id')->limit(100)->get();
        $selected = $this->selectedId ? $records->firstWhere('id', $this->selectedId) : null;
        if (! $selected && $this->selectedId) {
            $selected = $this->findRecord($definition, $this->selectedId);
        }
        $logs = TestDataCleanupLog::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->latest('deleted_at')
            ->limit(20)
            ->get();

        return view('livewire.v3.administration.test-data-cleanup', [
            ...$this->shellData($unit),
            'definitions' => $definitions,
            'definition' => $definition,
            'records' => $records,
            'selected' => $selected,
            'logs' => $logs,
        ])->layout('layouts.v3', ['title' => 'Pembersihan Data Uji']);
    }

    /** @param array<string, mixed> $definition */
    private function findRecord(array $definition, int $recordId): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $model = new $modelClass;
        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }
        if (Schema::hasColumn($model->getTable(), 'sppg_unit_id')) {
            $query->where('sppg_unit_id', $this->currentUnit()->getKey());
        }

        return $query->find($recordId);
    }

    public function displayValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return filled($value) ? (string) $value : '—';
    }

    public function displayDate(mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        return Carbon::parse($value)->translatedFormat('d M Y, H:i');
    }
}
