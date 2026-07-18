<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 4px; text-align: center; }
        h2 { font-size: 12px; margin: 14px 0 5px; background: #e5e7eb; padding: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #9ca3af; padding: 4px; vertical-align: top; }
        th { background: #f3f4f6; }
        .meta td { border: none; padding: 2px 4px; }
        .center { text-align: center; }
        .photo { width: 120px; max-height: 100px; object-fit: contain; }
        .small { font-size: 8px; color: #4b5563; }
    </style>
</head>
<body>
<h1>LAPORAN KEBERSIHAN TERPADU</h1>
<div class="center">{{ $session->sppgUnit?->name }}</div>

<table class="meta">
    <tr>
        <td><strong>Nomor</strong></td><td>{{ $session->session_number }}</td>
        <td><strong>Tanggal</strong></td><td>{{ $session->scheduled_date?->format('d M Y') }}</td>
    </tr>
    <tr>
        <td><strong>Area</strong></td><td>{{ $session->cleaningArea?->name }}</td>
        <td><strong>Shift</strong></td><td>{{ ucfirst(str_replace('_', ' ', $session->shift)) }}</td>
    </tr>
    <tr>
        <td><strong>Petugas</strong></td><td>{{ $session->petugas_name_snapshot }}</td>
        <td><strong>Tahap/Status</strong></td><td>{{ $session->state->label() }} / {{ $session->status->label() }}</td>
    </tr>
    <tr>
        <td><strong>Mulai</strong></td><td>{{ $session->started_at?->format('d/m/Y H:i') ?? '-' }}</td>
        <td><strong>Selesai</strong></td><td>{{ $session->completed_at?->format('d/m/Y H:i') ?? '-' }}</td>
    </tr>
</table>

<h2>Kondisi Area</h2>
<table>
    <tr><th>Sebelum</th><th>Setelah</th></tr>
    <tr><td>{{ $session->before_condition ?: '-' }}</td><td>{{ $session->after_condition ?: '-' }}</td></tr>
</table>

<h2>Checklist Kebersihan</h2>
<table>
    <tr><th>No</th><th>Kategori</th><th>Pemeriksaan</th><th>Hasil</th><th>Catatan</th></tr>
    @foreach($session->checklistItems as $item)
        <tr>
            <td class="center">{{ $loop->iteration }}</td>
            <td>{{ $item->category }}</td>
            <td>{{ $item->item_name }}</td>
            <td class="center">{{ match($item->result) { 'pass' => 'Lulus', 'fail' => 'Tidak Lulus', 'not_applicable' => 'Tidak Berlaku', default => 'Belum Diperiksa' } }}</td>
            <td>{{ $item->notes }}</td>
        </tr>
    @endforeach
</table>

<h2>Bahan Pembersih</h2>
<table>
    <tr><th>No</th><th>Nama</th><th>Jumlah</th><th>Kegunaan</th><th>Pengenceran</th><th>Batch/Kedaluwarsa</th></tr>
    @foreach($session->chemicalUsages as $item)
        <tr>
            <td class="center">{{ $loop->iteration }}</td>
            <td>{{ $item->chemical_name }}</td>
            <td>{{ $item->quantity }} {{ $item->unit }}</td>
            <td>{{ $item->purpose }}</td>
            <td>{{ $item->dilution_ratio }}</td>
            <td>{{ $item->batch_number }} / {{ $item->expiry_date?->format('d/m/Y') }}</td>
        </tr>
    @endforeach
</table>

@if($session->findings->isNotEmpty())
<h2>Temuan dan Tindakan Koreksi</h2>
<table>
    <tr><th>Waktu</th><th>Kategori/Tingkat</th><th>Temuan</th><th>Tindakan</th><th>Status</th></tr>
    @foreach($session->findings as $item)
        <tr>
            <td>{{ $item->found_at?->format('d/m/Y H:i') }}</td>
            <td>{{ $item->category }} / {{ $item->severity->label() }}</td>
            <td>{{ $item->description }}</td>
            <td>{{ $item->corrective_action }}</td>
            <td>{{ $item->status->label() }}</td>
        </tr>
    @endforeach
</table>
@endif

@if($session->wasteRecords->isNotEmpty())
<h2>Limbah Kebersihan</h2>
<table>
    <tr><th>No</th><th>Jenis</th><th>Jumlah</th><th>Penanganan</th><th>Diserahkan Kepada</th></tr>
    @foreach($session->wasteRecords as $item)
        <tr>
            <td class="center">{{ $loop->iteration }}</td>
            <td>{{ $item->waste_type }}</td>
            <td>{{ $item->quantity }} {{ $item->unit }}</td>
            <td>{{ $item->disposal_method }}</td>
            <td>{{ $item->handed_over_to }}</td>
        </tr>
    @endforeach
</table>
@endif

<h2>Dokumentasi</h2>
<table><tr>
@foreach($session->documentations as $item)
    <td class="center">
        @php($path = $item->photo_path ? public_path('storage/' . $item->photo_path) : null)
        @if($path && file_exists($path))
            <img class="photo" src="{{ $path }}"><br>
        @endif
        <strong>{{ ucfirst(str_replace('_', ' ', $item->phase)) }}</strong><br>
        <span class="small">{{ $item->caption }}</span>
    </td>
    @if($loop->iteration % 4 === 0)</tr><tr>@endif
@endforeach
</tr></table>

<p><strong>Catatan:</strong> {{ $session->notes ?: '-' }}</p>
<table class="meta">
    <tr>
        <td class="center">Petugas<br><br><br><strong>{{ $session->petugas_name_snapshot }}</strong></td>
        <td class="center">Supervisor<br><br><br><strong>{{ $session->supervisor_name_snapshot ?? '-' }}</strong></td>
        <td class="center">Verifikator<br><br><br><strong>{{ $session->verifier?->name ?? '-' }}</strong></td>
    </tr>
</table>
</body>
</html>
