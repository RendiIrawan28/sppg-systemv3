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
<h1>LAPORAN PENCUCIAN TERPADU</h1>
<div class="center">{{ $session->sppgUnit?->name }}</div>
<table class="meta">
    <tr><td><strong>Nomor</strong></td><td>{{ $session->session_number }}</td><td><strong>Tanggal</strong></td><td>{{ $session->washing_date?->format('d M Y') }}</td></tr>
    <tr><td><strong>Distribusi Sumber</strong></td><td>{{ $session->distributionRun?->run_number ?? '-' }}</td><td><strong>Petugas</strong></td><td>{{ $session->petugas_name_snapshot }}</td></tr>
    <tr><td><strong>Area</strong></td><td>{{ $session->washing_area }}</td><td><strong>Tahap/Status</strong></td><td>{{ $session->state->label() }} / {{ $session->status->label() }}</td></tr>
</table>

<h2>Rekonsiliasi Ompreng</h2>
<table><tr><th>Diharapkan</th><th>Diterima</th><th>Dicuci</th><th>Bersih</th><th>Rusak</th><th>Ditolak</th><th>Hilang</th></tr>
<tr class="center"><td>{{ $session->expected_containers }}</td><td>{{ $session->received_containers }}</td><td>{{ $session->washed_containers }}</td><td>{{ $session->clean_containers }}</td><td>{{ $session->damaged_containers }}</td><td>{{ $session->rejected_containers }}</td><td>{{ $session->missing_containers }}</td></tr></table>

<h2>Checklist</h2>
<table><tr><th>No</th><th>Tahap</th><th>Pemeriksaan</th><th>Hasil</th><th>Catatan</th></tr>
@foreach($session->checklistItems as $item)
<tr><td class="center">{{ $loop->iteration }}</td><td>{{ $item->category }}</td><td>{{ $item->item_name }}</td><td class="center">{{ $item->is_passed ? 'Lulus' : 'Belum/Tidak Lulus' }}</td><td>{{ $item->notes }}</td></tr>
@endforeach</table>

<h2>Pengukuran</h2>
<table><tr><th>Waktu</th><th>Tahap</th><th>Suhu</th><th>Batas</th><th>pH</th><th>Sanitizer</th><th>Hasil/Koreksi</th></tr>
@foreach($session->measurements as $item)
<tr><td>{{ $item->measured_at?->format('d/m/Y H:i') }}</td><td>{{ $item->phase }}</td><td>{{ $item->water_temperature_celsius }} °C</td><td>{{ $item->minimum_temperature_celsius ?? '-' }} – {{ $item->maximum_temperature_celsius ?? '-' }} °C</td><td>{{ $item->water_ph ?? '-' }}</td><td>{{ $item->sanitizer_concentration_ppm ?? '-' }} ppm</td><td>{{ $item->is_within_limit ? 'Sesuai' : 'Tidak sesuai' }}<br>{{ $item->corrective_action }}</td></tr>
@endforeach</table>

<h2>Bahan Pembersih</h2>
<table><tr><th>No</th><th>Nama</th><th>Jumlah</th><th>Kegunaan</th><th>Batch/Kedaluwarsa</th></tr>
@foreach($session->chemicalUsages as $item)
<tr><td class="center">{{ $loop->iteration }}</td><td>{{ $item->chemical_name }}</td><td>{{ $item->quantity }} {{ $item->unit }}</td><td>{{ $item->purpose }}</td><td>{{ $item->batch_number }} / {{ $item->expiry_date?->format('d/m/Y') }}</td></tr>
@endforeach</table>

@if($session->wasteRecords->isNotEmpty())
<h2>Limbah Pencucian</h2>
<table><tr><th>No</th><th>Jenis</th><th>Jumlah</th><th>Penanganan</th><th>Diserahkan Kepada</th></tr>
@foreach($session->wasteRecords as $item)
<tr><td class="center">{{ $loop->iteration }}</td><td>{{ $item->waste_type }}</td><td>{{ $item->quantity }} {{ $item->unit }}</td><td>{{ $item->disposal_method }}</td><td>{{ $item->handed_over_to }}</td></tr>
@endforeach</table>
@endif

<h2>Dokumentasi</h2>
<table><tr>
@foreach($session->documentations as $item)
<td class="center">
@php($path = $item->photo_path ? public_path('storage/' . $item->photo_path) : null)
@if($path && file_exists($path))<img class="photo" src="{{ $path }}"><br>@endif
<strong>{{ ucfirst($item->phase) }}</strong><br><span class="small">{{ $item->caption }}</span>
</td>
@if($loop->iteration % 4 === 0)</tr><tr>@endif
@endforeach
</tr></table>

@if($session->deviations->isNotEmpty())
<h2>Penyimpangan</h2>
<table><tr><th>Waktu</th><th>Kategori/Tingkat</th><th>Deskripsi</th><th>Tindakan</th><th>Status</th></tr>
@foreach($session->deviations as $item)
<tr><td>{{ $item->occurred_at?->format('d/m/Y H:i') }}</td><td>{{ $item->category }} / {{ $item->severity->label() }}</td><td>{{ $item->description }}</td><td>{{ $item->immediate_action }}</td><td>{{ $item->status->label() }}</td></tr>
@endforeach</table>
@endif

<p><strong>Catatan:</strong> {{ $session->notes ?: '-' }}</p>
<table class="meta"><tr><td class="center">Petugas<br><br><br><strong>{{ $session->petugas_name_snapshot }}</strong></td><td class="center">Verifikator<br><br><br><strong>{{ $session->verifier?->name ?? '-' }}</strong></td></tr></table>
</body>
</html>
