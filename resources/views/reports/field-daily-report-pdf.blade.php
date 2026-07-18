<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Harian Asisten Lapangan</title>
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { margin: 0; font-size: 17px; text-align: center; }
        h2 { margin: 4px 0 14px; font-size: 12px; text-align: center; font-weight: normal; }
        h3 { margin: 14px 0 6px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 5px; vertical-align: top; }
        .meta .label { width: 130px; font-weight: bold; }
        .data th, .data td { border: 1px solid #9ca3af; padding: 5px; vertical-align: top; }
        .data th { background: #e5e7eb; }
        .metrics td { border: 1px solid #9ca3af; padding: 6px; width: 25%; }
        .metrics .label { display: block; color: #4b5563; font-size: 8px; }
        .metrics .value { display: block; margin-top: 2px; font-size: 14px; font-weight: bold; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .narrative { border: 1px solid #d1d5db; padding: 8px; min-height: 34px; white-space: pre-wrap; }
        .signatures { margin-top: 30px; text-align: center; }
        .signatures td { width: 50%; }
        .space { height: 55px; }
        .page-break { page-break-before: always; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>LAPORAN HARIAN ASISTEN LAPANGAN</h1>
    <h2>{{ $report->sppgUnit?->name ?? 'Unit SPPG' }}</h2>

    <table class="meta">
        <tr>
            <td class="label">Nomor Laporan</td><td>: {{ $report->report_number }}</td>
            <td class="label">Tanggal</td><td>: {{ $report->report_date?->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Rencana Distribusi</td><td>: {{ $report->plan?->plan_number ?? '-' }}</td>
            <td class="label">Status</td><td>: {{ $report->status?->label() }}</td>
        </tr>
        <tr>
            <td class="label">Penyusun</td><td>: {{ $report->prepared_by_name_snapshot ?? $report->preparer?->name ?? '-' }}</td>
            <td class="label">Data Diperbarui</td><td>: {{ $report->generated_at?->format('d M Y H:i') ?? '-' }}</td>
        </tr>
    </table>

    <h3>Ringkasan Penerima dan Distribusi</h3>
    <table class="metrics">
        <tr>
            <td><span class="label">Penerima Terdaftar</span><span class="value">{{ number_format($report->planned_beneficiaries) }}</span></td>
            <td><span class="label">Penerima Terkonfirmasi</span><span class="value">{{ number_format($report->confirmed_beneficiaries) }}</span></td>
            <td><span class="label">Penerima Aktual</span><span class="value">{{ number_format($report->actual_beneficiaries) }}</span></td>
            <td><span class="label">Porsi Direncanakan</span><span class="value">{{ number_format($report->planned_portions) }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Porsi Diproduksi</span><span class="value">{{ number_format($report->produced_portions) }}</span></td>
            <td><span class="label">Porsi Diporsikan</span><span class="value">{{ number_format($report->portioned_portions) }}</span></td>
            <td><span class="label">Porsi Terkirim</span><span class="value">{{ number_format($report->delivered_portions) }}</span></td>
            <td><span class="label">Porsi Kembali</span><span class="value">{{ number_format($report->returned_portions) }}</span></td>
        </tr>
        <tr>
            <td><span class="label">Tujuan Berhasil / Rencana</span><span class="value">{{ $report->completed_destinations }} / {{ $report->planned_destinations }}</span></td>
            <td><span class="label">Tujuan Gagal</span><span class="value">{{ number_format($report->failed_destinations) }}</span></td>
            <td><span class="label">Tujuan Terlambat</span><span class="value">{{ number_format($report->late_destinations) }}</span></td>
            <td><span class="label">Insiden Terbuka</span><span class="value">{{ number_format($report->open_incidents) }}</span></td>
        </tr>
    </table>

    <h3>Rekonsiliasi Ompreng</h3>
    <table class="data">
        <thead><tr><th>Dikirim</th><th>Kembali</th><th>Rusak</th><th>Hilang</th><th>Selisih Belum Terjelaskan</th></tr></thead>
        <tbody>
            <tr class="text-center">
                <td>{{ number_format($report->containers_sent) }}</td>
                <td>{{ number_format($report->containers_returned) }}</td>
                <td>{{ number_format($report->containers_damaged) }}</td>
                <td>{{ number_format($report->containers_lost) }}</td>
                <td>{{ number_format($report->containers_sent - $report->containers_returned - $report->containers_damaged - $report->containers_lost) }}</td>
            </tr>
        </tbody>
    </table>

    <h3>Kelengkapan Enam Divisi</h3>
    <table class="data">
        <thead>
            <tr><th>Divisi</th><th>Total</th><th>Draft</th><th>Diajukan</th><th>Revisi</th><th>Terverifikasi</th><th>Status</th></tr>
        </thead>
        <tbody>
            @forelse ($report->divisions as $division)
                <tr>
                    <td>{{ $division->division_name }}</td>
                    <td class="text-center">{{ $division->total_records }}</td>
                    <td class="text-center">{{ $division->draft_records }}</td>
                    <td class="text-center">{{ $division->submitted_records }}</td>
                    <td class="text-center">{{ $division->revision_records }}</td>
                    <td class="text-center">{{ $division->verified_records }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $division->completion_status)) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center">Ringkasan divisi belum tersedia.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Catatan Asisten Lapangan</h3>
    <strong>Ringkasan Operasional</strong>
    <div class="narrative">{{ $report->operational_summary ?: '-' }}</div>
    <br>
    <strong>Kendala</strong>
    <div class="narrative">{{ $report->obstacles ?: '-' }}</div>
    <br>
    <strong>Evaluasi</strong>
    <div class="narrative">{{ $report->evaluation ?: '-' }}</div>
    <br>
    <strong>Tindak Lanjut</strong>
    <div class="narrative">{{ $report->follow_up ?: '-' }}</div>
    <br>
    <strong>Rekomendasi</strong>
    <div class="narrative">{{ $report->recommendations ?: '-' }}</div>

    @if ($report->incidents->isNotEmpty())
        <div class="page-break"></div>
        <h3>Daftar Insiden dan Temuan</h3>
        <table class="data">
            <thead>
                <tr><th>No</th><th>Divisi</th><th>Kategori</th><th>Keparahan</th><th>Status</th><th>Deskripsi</th><th>Tindakan/Penyelesaian</th></tr>
            </thead>
            <tbody>
                @foreach ($report->incidents as $index => $incident)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $incident->division_code ?: '-' }}</td>
                        <td>{{ $incident->category ?: '-' }}</td>
                        <td>{{ $incident->severity ?: '-' }}</td>
                        <td>{{ $incident->status ?: '-' }}</td>
                        <td><strong>{{ $incident->title }}</strong><br>{{ $incident->description }}</td>
                        <td>{{ $incident->action_or_resolution ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signatures">
        <tr>
            <td>Disusun oleh,<br>Asisten Lapangan<div class="space"></div><strong>{{ $report->prepared_by_name_snapshot ?? $report->preparer?->name ?? '________________' }}</strong></td>
            <td>Disetujui oleh,<br>Kepala SPPG<div class="space"></div><strong>{{ $report->approver?->name ?? '________________' }}</strong></td>
        </tr>
    </table>
</body>
</html>
