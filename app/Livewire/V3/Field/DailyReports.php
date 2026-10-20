<?php

namespace App\Livewire\V3\Field;

use App\Enums\FieldDailyReportStatus;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDailyReport;
use App\Models\FieldWeeklyReportPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use App\Services\BulkOperationalReportReviewService;
use App\Services\FieldDailyReportGenerator;
use App\Services\FieldDailyReportWorkflow;
use DomainException;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

class DailyReports extends Component
{
    use FiltersByWorkDate;
    use InteractsWithV3Shell;
    use WithPagination;
    use WithFileUploads;

    public ?int $selectedId = null;

    public array $form = [
        'operational_summary' => '',
        'obstacles' => '',
        'evaluation' => '',
        'follow_up' => '',
        'recommendations' => '',
    ];

    public string $reviewNotes = '';

    public string $weekStart = '';

    public string $uploadDate = '';

    public string $uploadType = 'menu_school';

    public string $uploadCaption = '';

    public $uploadImage = null;

    public bool $includeMenuPhotos = true;

    public bool $includeActivityPhotos = true;

    public bool $includeWaybills = true;

    public function mount(): void
    {
        $this->weekStart = CarbonImmutable::today()->startOfWeek()->toDateString();
        $this->uploadDate = $this->weekStart;
    }

    public function updatedWeekStart(): void
    {
        try {
            $this->weekStart = CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart)
                ->startOfWeek()->toDateString();
        } catch (\Throwable) {
            $this->weekStart = CarbonImmutable::today()->startOfWeek()->toDateString();
        }
        $this->uploadDate = $this->weekStart;
    }

    public function uploadWeeklyPhoto(): void
    {
        abort_unless($this->allowed('field_daily_reports.update'), 403);
        $this->validate([
            'weekStart' => ['required', 'date_format:Y-m-d'],
            'uploadDate' => ['required', 'date_format:Y-m-d'],
            'uploadType' => ['required', 'in:menu_school,menu_3b,activity'],
            'uploadCaption' => ['nullable', 'string', 'max:255'],
            'uploadImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $first = CarbonImmutable::parse($this->weekStart)->startOfWeek();
        $date = CarbonImmutable::parse($this->uploadDate);
        if ($date->lt($first) || $date->gt($first->addDays(4))) {
            $this->addError('uploadDate', 'Tanggal foto harus berada dalam periode Senin sampai Jumat yang dipilih.');
            return;
        }
        $unit = $this->currentUnit();
        // Private storage: pictures are only embedded through the authorized PDF export.
        $path = $this->uploadImage->store('field-weekly-photos/'.$unit->getKey(), 'local');
        FieldWeeklyReportPhoto::query()->create([
            'sppg_unit_id' => $unit->getKey(),
            'photo_date' => $date->toDateString(),
            'photo_type' => $this->uploadType,
            'photo_path' => $path,
            'caption' => trim($this->uploadCaption) ?: null,
            'uploaded_by' => auth()->id(),
        ]);
        $this->reset('uploadImage', 'uploadCaption');
        session()->flash('v3.status', 'Foto laporan mingguan berhasil disimpan.');
    }

    public function deleteWeeklyPhoto(int $photoId): void
    {
        abort_unless($this->allowed('field_daily_reports.update'), 403);
        $photo = FieldWeeklyReportPhoto::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($photoId);
        $path = $photo->photo_path;
        $photo->delete();
        Storage::disk('local')->delete($path);
        session()->flash('v3.status', 'Foto laporan mingguan dihapus.');
    }



    public function select(int $id): void
    {
        $report = $this->record($id);
        $this->selectedId = $id;
        foreach (array_keys($this->form) as $field) {
            $this->form[$field] = (string) ($report->{$field} ?? '');
        }
        $this->reviewNotes = (string) ($report->review_notes ?? '');
        $this->resetValidation();
    }

    public function refreshReport(FieldDailyReportGenerator $generator): void
    {
        $report = $this->record($this->selectedId);
        abort_unless($this->allowed('field_daily_reports.update'), 403);
        $generator->generate((int) $report->sppg_unit_id, $report->report_date->toDateString(), auth()->user(), $report);
        $this->select($report->getKey());
        session()->flash('v3.status', 'Rekap laporan berhasil diperbarui.');
    }

    public function save(FieldDailyReportWorkflow $workflow): void
    {
        $this->validate([
            'form.operational_summary' => ['required', 'string', 'max:5000'],
            'form.obstacles' => ['nullable', 'string', 'max:5000'],
            'form.evaluation' => ['required', 'string', 'max:5000'],
            'form.follow_up' => ['required', 'string', 'max:5000'],
            'form.recommendations' => ['nullable', 'string', 'max:5000'],
        ]);
        $workflow->update($this->record($this->selectedId), auth()->user(), $this->form);
        $this->select($this->selectedId);
        session()->flash('v3.status', 'Catatan laporan harian disimpan.');
    }

    public function submit(FieldDailyReportWorkflow $workflow): void
    {
        $this->save($workflow);
        try {
            $workflow->submit($this->record($this->selectedId), auth()->user(), trim($this->reviewNotes) ?: null);
        } catch (DomainException $exception) {
            $this->addError('workflow', nl2br(e($exception->getMessage())));

            return;
        }
        $this->select($this->selectedId);
        session()->flash('v3.status', 'Laporan diajukan kepada Kepala SPPG.');
    }

    public function approve(FieldDailyReportWorkflow $workflow): void
    {
        try {
            $workflow->approve($this->record($this->selectedId), auth()->user(), trim($this->reviewNotes) ?: null);
        } catch (DomainException $exception) {
            $this->addError('workflow', $exception->getMessage());

            return;
        }
        $this->select($this->selectedId);
        session()->flash('v3.status', 'Laporan harian disetujui.');
    }

    public function approveAll(BulkOperationalReportReviewService $bulk, FieldDailyReportWorkflow $workflow): void
    {
        $count = $bulk->review(
            FieldDailyReport::query()->where('sppg_unit_id', $this->currentUnit()->getKey())
                ->whereDate('report_date', $this->selectedWorkDate())
                ->where('submitted_by', '!=', auth()->id()),
            auth()->user(), 'field_daily_reports.approve',
            fn (FieldDailyReport $report, $actor) => $workflow->approve($report, $actor),
            FieldDailyReportStatus::Submitted->value,
        );
        session()->flash('v3.status', "{$count} laporan harian lapangan berhasil disetujui.");
    }

    public function requestRevision(FieldDailyReportWorkflow $workflow): void
    {
        $this->validate(['reviewNotes' => ['required', 'string', 'max:2000']]);
        try {
            $workflow->requestRevision($this->record($this->selectedId), auth()->user(), $this->reviewNotes);
        } catch (DomainException $exception) {
            $this->addError('workflow', $exception->getMessage());

            return;
        }
        $this->select($this->selectedId);
        session()->flash('v3.status', 'Laporan dikembalikan untuk revisi.');
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('field_daily_reports.view'), 403);
        $reports = FieldDailyReport::query()->with(['plan', 'divisions', 'incidents'])
            ->where('sppg_unit_id', $unit->getKey())
            ->whereDate('report_date', $this->selectedWorkDate())
            ->latest('report_date')->paginate(15);
        $selected = $this->selectedId
            ? FieldDailyReport::query()->with(['plan', 'divisions', 'incidents'])->where('sppg_unit_id', $unit->getKey())->find($this->selectedId)
            : null;

        $weekStart = CarbonImmutable::parse($this->weekStart ?: now()->toDateString())->startOfWeek();
        $weeklyPhotos = FieldWeeklyReportPhoto::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereBetween('photo_date', [$weekStart->toDateString(), $weekStart->addDays(4)->toDateString()])
            ->orderBy('photo_date')->orderBy('id')->get();

        return view('livewire.v3.field.daily-reports', [
            'weeklyPhotos' => $weeklyPhotos,
            'weekEnd' => $weekStart->addDays(4)->toDateString(),
            ...$this->shellData($unit),
            'reports' => $reports,
            'selected' => $selected,
            'canUpdate' => $this->allowed('field_daily_reports.update'),
            'canSubmit' => $this->allowed('field_daily_reports.submit'),
            'canApprove' => $this->allowed('field_daily_reports.approve'),
            'bulkReviewCount' => app(BulkOperationalReportReviewService::class)->pendingCount(
                FieldDailyReport::query()->where('sppg_unit_id', $unit->getKey())
                    ->whereDate('report_date', $this->selectedWorkDate())
                    ->where('submitted_by', '!=', auth()->id()),
                auth()->user(), 'field_daily_reports.approve', FieldDailyReportStatus::Submitted->value,
            ),
            'statusOptions' => FieldDailyReportStatus::options(),
        ])->layout('layouts.v3', ['title' => 'Laporan Harian Lapangan']);
    }

    protected function afterWorkDateChanged(): void
    {
        $this->selectedId = null;
    }

    private function record(?int $id): FieldDailyReport
    {
        return FieldDailyReport::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($id);
    }
}
