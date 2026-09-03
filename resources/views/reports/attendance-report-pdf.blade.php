<!doctype html>
<html lang="id"><head><meta charset="utf-8"><style>
@page{margin:24px 22px 30px}body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#172537}h1,p{margin:0}.header{text-align:center;margin-bottom:16px}.header h1{font-size:16px}.header p{margin-top:5px}table{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:14px}thead{display:table-header-group}tr{page-break-inside:avoid}th,td{border:1px solid #66758a;padding:5px 3px;vertical-align:middle;overflow-wrap:break-word}th{background:#eaf0f6;text-align:center;font-size:7px}.division th{text-align:left;font-size:10px;background:#dbe5f0;padding:7px}.center{text-align:center}.late{font-weight:bold;color:#82480b}.muted{font-size:7px;color:#526174}.signature{margin-top:20px;width:240px;margin-left:auto;text-align:center;page-break-inside:avoid}.signature-space{height:45px}
</style></head><body>
<div class="header"><h1>REKAP PRESENSI PEGAWAI</h1><p>{{ $unit->name }}</p><p>Periode {{ \Carbon\Carbon::parse($from)->format('d-m-Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d-m-Y') }} | {{ $divisionLabel }}</p></div>
@php($printedSection = false)
@forelse($groups as $group)
@foreach($group['sessions']->chunk(12) as $chunk)
@php($pageStyle = $printedSection ? 'page-break-before:always' : '')
@php($printedSection = true)
<table style="{{ $pageStyle }}"><colgroup><col style="width:3%"><col style="width:7%"><col style="width:12%"><col style="width:10%"><col style="width:8%"><col style="width:9%"><col style="width:6%"><col style="width:6%"><col style="width:5%"><col style="width:7%"><col style="width:9%"><col style="width:6%"><col style="width:12%"></colgroup>
<thead><tr class="division"><th colspan="13">{{ $group['name'] }}</th></tr><tr><th>No</th><th>Tanggal</th><th>Nama Pegawai</th><th>UID</th><th>Shift</th><th>Jadwal<br>Masuk - Pulang</th><th>Masuk</th><th>Pulang</th><th>Durasi</th><th>Status</th><th>Keterangan</th><th>Sumber</th><th>Catatan</th></tr></thead>
<tbody>@foreach($chunk as $index => $session)<tr>
<td class="center">{{ $index+1 }}</td><td class="center">{{ $session->work_date->format('d-m-Y') }}</td><td>{{ $session->user?->name ?? '-' }}</td><td class="center">{{ $session->user?->employee_number ?? '-' }}</td><td>{{ $session->shift_name_snapshot ?? '-' }}</td>
<td class="center">{{ $session->scheduled_check_in_at?->format('H:i') ?? '-' }} - {{ $session->scheduled_check_out_at?->format('H:i') ?? '-' }}@if($session->scheduled_check_out_at && $session->scheduled_check_in_at && ! $session->scheduled_check_out_at->isSameDay($session->scheduled_check_in_at))<br><span class="muted">(+1 hari)</span>@endif</td>
<td class="center">{{ $session->check_in_at?->format('H:i') ?? '-' }}@if($session->check_in_at && ! $session->check_in_at->isSameDay($session->work_date))<br><span class="muted">{{ $session->check_in_at->format('d-m') }}</span>@endif</td><td class="center">{{ $session->check_out_at?->format('H:i') ?? '-' }}@if($session->check_out_at && ! $session->check_out_at->isSameDay($session->work_date))<br><span class="muted">{{ $session->check_out_at->format('d-m') }}</span>@endif</td>
<td class="center">@if($session->durationMinutes() !== null){{ intdiv($session->durationMinutes(),60) }}j {{ $session->durationMinutes()%60 }}m @else-@endif</td><td class="center">{{ $session->statusLabel() }}</td><td class="center {{ $session->punctuality_status === 'late' ? 'late' : '' }}">{{ $session->attendanceRemark() }}</td><td class="center">{{ $session->sourceLabel() }}</td><td>{{ $session->notes ?? '-' }}</td>
</tr>@endforeach</tbody></table>
@endforeach
@empty<p class="center">Tidak ada data presensi pada periode dan divisi yang dipilih.</p>@endforelse
<div class="signature"><p>Mengetahui,<br>Kepala SPPG</p><div class="signature-space"></div><p><strong>{{ $unit->head_name ?: '________________________' }}</strong></p></div>
</body></html>
