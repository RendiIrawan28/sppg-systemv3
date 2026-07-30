<?php

namespace App\Livewire\V3\Field;

use App\Enums\FieldDailyReportStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDailyReport;
use App\Services\FieldDailyReportGenerator;
use App\Services\FieldDailyReportWorkflow;
use DomainException;
use Livewire\Component;
use Livewire\WithPagination;

class DailyReports extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    public ?int $selectedId = null;
    public array $form = [
        'operational_summary' => '',
        'obstacles' => '',
        'evaluation' => '',
        'follow_up' => '',
        'recommendations' => '',
    ];
    public string $reviewNotes = '';

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
            ->where('sppg_unit_id', $unit->getKey())->latest('report_date')->paginate(15);
        $selected = $this->selectedId
            ? FieldDailyReport::query()->with(['plan', 'divisions', 'incidents'])->where('sppg_unit_id', $unit->getKey())->find($this->selectedId)
            : null;

        return view('livewire.v3.field.daily-reports', [
            ...$this->shellData($unit),
            'reports' => $reports,
            'selected' => $selected,
            'canUpdate' => $this->allowed('field_daily_reports.update'),
            'canSubmit' => $this->allowed('field_daily_reports.submit'),
            'canApprove' => $this->allowed('field_daily_reports.approve'),
            'statusOptions' => FieldDailyReportStatus::options(),
        ])->layout('layouts.v3', ['title' => 'Laporan Harian Lapangan']);
    }

    private function record(?int $id): FieldDailyReport
    {
        return FieldDailyReport::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($id);
    }
}
