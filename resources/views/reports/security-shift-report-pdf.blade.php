<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Shift Keamanan</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        h1, p { margin: 0; }
        .title { text-align: center; margin-bottom: 14px; }
        .title h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .meta td { border: 1px solid #cbd5e1; padding: 7px; }
        table.report { width: 100%; border-collapse: collapse; }
        .report th, .report td { border: 1px solid #94a3b8; padding: 6px; vertical-align: top; }
        .report th { background: #e2e8f0; text-align: center; }
        .center { text-align: center; }
        .muted { color: #64748b; }
        .signature { margin-top: 24px; width: 100%; }
        .signature td { width: 50%; text-align: center; vertical-align: top; }
        .space { height: 50px; }
    </style>
</head>
<body>
    <div class="title">
        <h1>LAPORAN SHIFT KEAMANAN</h1>
        <p>{{ $unit->name }}</p>
    </div>
    <table class="meta">
        <tr><td><strong>Petugas</strong><br>{{ $shift->officer_name_snapshot }}</td><td><strong>Mulai shift</strong><br>{{ $shift->started_at->translatedFormat('d F Y, H:i') }}</td><td><strong>Selesai/batas shift</strong><br>{{ ($shift->completed_at ?? $shift->scheduled_end_at)->translatedFormat('d F Y, H:i') }}</td><td><strong>Status</strong><br>{{ $shift->status->label() }}</td></tr>
    </table>
    <table class="report">
        <thead><tr><th>Jam ke</th><th>Target</th><th>Waktu laporan</th><th>Situasi</th><th>Gerbang</th><th>Lingkungan</th><th>Aktivitas orang/kendaraan</th><th>Tamu</th><th>Catatan</th></tr></thead>
        <tbody>
        @foreach(range(1, $shift->reports_expected) as $sequence)
            @php($report = $shift->reports->firstWhere('sequence_number', $sequence))
            <tr>
                <td class="center">{{ $sequence }}</td>
                <td class="center">{{ $shift->started_at->copy()->addHours(3 * $sequence)->format('d/m/Y H:i') }}</td>
                <td class="center">{{ $report?->reported_at?->format('d/m/Y H:i') ?: '-' }}</td>
                <td class="center">{{ $report?->situation?->label() ?: 'Tidak dilaporkan' }}</td>
                <td class="center">{{ $report ? ($report->gate_secure ? 'Aman' : 'Perlu perhatian') : '-' }}</td>
                <td class="center">{{ $report ? ($report->perimeter_secure ? 'Aman' : 'Perlu perhatian') : '-' }}</td>
                <td>{{ $report?->access_activity ?: '-' }}</td>
                <td>{{ $report?->visitor_activity ?: '-' }}</td>
                <td>{{ $report?->notes ?: '-' }}@if($report?->photo_path)<br><span class="muted">Foto dokumentasi tersedia di sistem.</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="signature"><tr><td>Petugas Keamanan<div class="space"></div><strong>{{ $shift->officer_name_snapshot }}</strong></td><td>Mengetahui,<br>Kepala SPPG<div class="space"></div><strong>{{ $unit->head_name ?: '(................................)' }}</strong></td></tr></table>
</body>
</html>
