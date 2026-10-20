<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18mm 16mm 18mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; color: #17253b; font-size: 9px; line-height: 1.55; }
    h1, h2, h3, p { margin: 0; }
    h1 { font-size: 17px; }
    h2 { font-size: 14px; text-transform: uppercase; text-align: center; margin: 10px 0 16px; }
    h3 { font-size: 10px; margin: 15px 0 7px; }
    .center { text-align:center; }
    .muted { color: #596579; }
    .small { font-size: 7.5px; }
    .cover { text-align:center; min-height: 95%; padding-top: 50px; }
    .cover-heading { font-size: 15px; line-height: 1.6; font-weight: bold; }
    .cover-logo { width: 165px; height: auto; margin: 75px auto 80px; }
    .cover-period { font-size: 16px; font-weight: bold; margin-bottom: 10px; }
    .cover-stamp { margin-top: 20px; border: 1px solid #8ea0b5; display:inline-block; padding: 5px 12px; color: #6b3441; font-size: 10px; }
    .page-break { page-break-before: always; }
    .data { width: 100%; border-collapse: collapse; margin: 7px 0 14px; table-layout: fixed; }
    .data th { background: #e4edf7; color: #132842; font-weight: bold; }
    .data th, .data td { border: 1px solid #8594a5; padding: 5px 6px; vertical-align: top; overflow-wrap: break-word; }
    .data tr { page-break-inside: avoid; }
    .kv { width: 100%; margin-bottom: 15px; }
    .kv td { padding: 3px 5px; border-bottom: 1px solid #e4e8ef; }
    .narrative { text-align: justify; white-space: pre-line; margin: 8px 0 10px; }
    .photos { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 10px 0 20px; }
    .photos td { width: 50%; vertical-align: top; text-align: center; }
    .photos img { max-width: 100%; max-height: 172px; height: auto; }
    .photo-frame { height: 180px; border: 1px solid #cad6e3; padding: 4px; text-align: center; }
    .photo-caption { font-size: 7.5px; text-align: center; margin-top: 4px; }
    .letterhead { border-bottom: 2px solid #203957; padding: 2px 0 9px; margin-bottom: 12px; }
    .logo-sm { height: 39px; float: left; margin-right: 8px; }
    .letterhead strong { font-size: 11px; }
    .status { padding: 5px 8px; border: 1px solid #b9c5d2; font-size: 8px; color: #566478; }
    .signatures { margin-top: 32px; width: 100%; page-break-inside: avoid; }
    .signatures td { width: 50%; text-align: center; vertical-align: top; }
    .sign-space { height: 67px; }
    .footnote { font-size: 7px; color: #576980; }
</style>
</head>
<body>
@php($logo = public_path('images/logo-bgn.png'))
<div class="cover">
    <div class="cover-heading">
        LAPORAN<br>
        PELAKSANAAN PROGRAM MAKAN BERGIZI GRATIS<br>
        SATUAN PELAYANAN PEMENUHAN GIZI<br>
        {{ strtoupper($unit->name) }}
    </div>
    @if(is_file($logo))<img class="cover-logo" src="{{ $logo }}" alt="Logo BGN">@endif
    <div class="cover-period">LAPORAN MINGGUAN</div>
    <div style="font-size:13px; font-weight:bold;">{{ $start->translatedFormat('d F') }} - {{ $end->translatedFormat('d F Y') }}</div>
    @unless($fully_approved)
        <div class="cover-stamp">DRAF / BELUM SEMUA LAPORAN HARIAN DISETUJUI</div>
    @endunless
</div>
<div class="page-break"></div>
<div class="letterhead">
    @if(is_file($logo))<img class="logo-sm" src="{{ $logo }}" alt="Logo BGN">@endif
    <strong>{{ strtoupper($unit->name) }}</strong><br>
    <span class="small">BADAN GIZI NASIONAL • LAPORAN KEGIATAN MINGGUAN</span><br>
    <span class="small">{{ $unit->address ?: '' }}</span>
</div>
<h2>BAB I<br>PENDAHULUAN</h2>
<h3>A. Latar Belakang</h3>
<p class="narrative">Laporan ini disusun untuk mendokumentasikan pelaksanaan Program Makan Bergizi Gratis oleh {{ $unit->name }} selama periode {{ $start->translatedFormat('d F Y') }} sampai {{ $end->translatedFormat('d F Y') }}. Informasi operasional dalam laporan bersumber dari catatan harian Asisten Lapangan, siklus menu, dan laporan distribusi yang telah tersimpan di sistem.</p>
<h3>B. Tujuan</h3>
<p class="narrative">Laporan bertujuan mencatat realisasi pelayanan, menyajikan menu yang direncanakan, merangkum dokumentasi yang tersedia, serta menindaklanjuti kendala dan evaluasi operasional berdasarkan data yang tercatat.</p>
<h3>C. Sasaran</h3>
<p class="narrative">Sasaran pelaksanaan meliputi sekolah, pondok pesantren, dan/atau posyandu yang tercantum pada catatan rute distribusi selama periode ini. Rincian pelayanan diuraikan pada BAB II dan lampiran ringkasan surat jalan.</p>
<div class="status">Laporan harian tersedia: {{ $daily_count }} dari 5 hari (Senin-Jumat). Hari tanpa laporan tidak dihitung sebagai nol pelayanan.</div>

<div class="page-break"></div>
<h2>BAB II<br>PELAKSANAAN KEGIATAN</h2>
<h3>A. Persiapan</h3>
<p class="narrative">Persiapan dan koordinasi operasional mengacu pada rencana distribusi serta menu yang tercatat dalam sistem. Rincian kegiatan khusus yang tidak tercatat tidak diasumsikan telah terlaksana.</p>
<h3>B. Pelaksanaan</h3>
<table class="kv">
    <tr><td style="width:40%">Jumlah hari dengan laporan</td><td>{{ $daily_count }} hari</td></tr>
    <tr><td>Akumulasi porsi terkirim</td><td>{{ number_format($total_delivered, 0, ',', '.') }} porsi</td></tr>
    <tr><td>Akumulasi porsi kembali</td><td>{{ number_format($total_returned, 0, ',', '.') }} porsi</td></tr>
    <tr><td>Akumulasi penyelesaian tujuan per hari</td><td>{{ number_format($total_destinations, 0, ',', '.') }} tujuan pelayanan</td></tr>
</table>
<h3>1. Rekap Penerima dan Realisasi Distribusi</h3>
<table class="data">
    <thead><tr><th style="width:23%">Tanggal</th><th style="width:15%">Rencana porsi</th><th style="width:15%">Porsi terkirim</th><th style="width:15%">Porsi kembali</th><th style="width:15%">Tujuan selesai</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($days as $day)
        <tr>
            <td>{{ $day['date']->translatedFormat('l, d M Y') }}</td>
            <td class="center">{{ $day['report'] ? number_format($day['report']->planned_portions, 0, ',', '.') : '-' }}</td>
            <td class="center">{{ $day['report'] ? number_format($day['report']->delivered_portions, 0, ',', '.') : '-' }}</td>
            <td class="center">{{ $day['report'] ? number_format($day['report']->returned_portions, 0, ',', '.') : '-' }}</td>
            <td class="center">{{ $day['report'] ? (int) $day['report']->completed_destinations : '-' }}</td>
            <td>{{ $day['report']?->status?->label() ?? 'Belum tersedia' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="footnote">Jumlah porsi merupakan akumulasi per tanggal pelayanan, bukan jumlah orang unik selama satu minggu. Nilai '-' berarti tidak ada laporan; bukan nol penerima.</p>
<h3>2. Jenis Menu MBG yang Diberikan</h3>
<p><strong>A. Menu Sekolah dan Pondok Pesantren</strong></p>
<table class="data">
    <thead><tr>@foreach($days as $day)<th style="width:20%">{{ $day['date']->translatedFormat('l') }}<br>{{ $day['date']->format('d/m/Y') }}</th>@endforeach</tr></thead>
    <tbody><tr>@foreach($days as $day)<td>@forelse($day['school_menu'] as $item){{ $item }}@unless($loop->last)<br>@endunless @empty <span class="muted">Belum tersedia</span> @endforelse</td>@endforeach</tr></tbody>
</table>
<p><strong>B. Menu 3B (Balita, Ibu Hamil, dan Ibu Menyusui)</strong></p>
<table class="data">
    <thead><tr>@foreach($days as $day)<th style="width:20%">{{ $day['date']->translatedFormat('l') }}<br>{{ $day['date']->format('d/m/Y') }}</th>@endforeach</tr></thead>
    <tbody><tr>@foreach($days as $day)<td>@forelse($day['three_b_menu'] as $item){{ $item }}@unless($loop->last)<br>@endunless @empty <span class="muted">Belum tersedia</span> @endforelse</td>@endforeach</tr></tbody>
</table>
<p class="footnote">Menu mengikuti snapshot siklus yang disetujui/aktif/diarsipkan. Jika menu 3B tidak mempunyai varian khusus, sistem menggunakan menu hari yang sama sesuai aturan aplikasi. Tidak ada poster menu yang dibuat otomatis.</p>

@if($includes['menu_photos'])
<div class="page-break"></div>
<h3>3. Dokumentasi Menu</h3>
@foreach($days as $day)
    <h3>{{ $day['date']->translatedFormat('l, d F Y') }}</h3>
    @php($menuPictures = array_merge($day['school_menu_photos'], $day['three_b_menu_photos']))
    @if(count($menuPictures))
        <table class="photos">
            @foreach(array_chunk($menuPictures, 2) as $pair)
            <tr>@foreach($pair as $picture)<td><div class="photo-frame"><img src="{{ $picture['uri'] }}"></div><div class="photo-caption">{{ $picture['caption'] ?: 'Dokumentasi menu' }}</div></td>@endforeach @if(count($pair)===1)<td></td>@endif</tr>
            @endforeach
        </table>
    @else
        <p class="muted">Foto/poster menu belum diunggah untuk tanggal ini.</p>
    @endif
@endforeach
@endif

@if($includes['activity_photos'])
<div class="page-break"></div>
<h3>4. Dokumentasi Kegiatan</h3>
<p class="muted">Foto bersumber dari serah-terima Distribusi dan dokumentasi tambahan Asisten Lapangan. Maksimal empat foto per hari untuk membatasi ukuran file.</p>
@foreach($days as $day)
    <h3>{{ $day['date']->translatedFormat('l, d F Y') }}</h3>
    @if(count($day['activity_photos']))
        <table class="photos">
            @foreach(array_chunk($day['activity_photos'], 2) as $pair)
            <tr>@foreach($pair as $picture)<td><div class="photo-frame"><img src="{{ $picture['uri'] }}"></div><div class="photo-caption">{{ $picture['caption'] ?: 'Dokumentasi kegiatan' }}</div></td>@endforeach @if(count($pair)===1)<td></td>@endif</tr>
            @endforeach
        </table>
    @else
        <p class="muted">Dokumentasi kegiatan belum tersedia pada tanggal ini.</p>
    @endif
@endforeach
@endif

@if($includes['waybills'])
<div class="page-break"></div>
<h2>Lampiran<br>Ringkasan Surat Jalan Distribusi</h2>
<p class="muted">Bagian ini merupakan rekap dari data perjalanan Distribusi. Dokumen surat jalan asli tetap dapat diunduh melalui modul Distribusi.</p>
@foreach($days as $day)
    <h3>{{ $day['date']->translatedFormat('l, d F Y') }}</h3>
    @if($day['runs']->count())
        <table class="data">
            <thead><tr><th>No. Surat / Rute</th><th>Kendaraan / Pengemudi</th><th>Tujuan</th><th>Porsi diserahkan</th></tr></thead>
            <tbody>@foreach($day['runs'] as $run)
                <tr>
                    <td>{{ $run->run_number ?? '-' }}<br>{{ $run->route_name ?? '-' }}</td>
                    <td>{{ $run->vehicle_name ?: '-' }}<br>{{ $run->driver_name ?: '-' }}</td>
                    <td>
                        @forelse($run->stops as $stop)
                            {{ $stop->destination_name }}@unless($loop->last), @endunless
                        @empty - @endforelse
                    </td>
                    <td>{{ number_format((int) $run->delivered_small_portions + (int) $run->delivered_large_portions, 0, ',', '.') }}</td>
                </tr>
            @endforeach</tbody>
        </table>
    @else
        <p class="muted">Surat jalan belum tersedia.</p>
    @endif
@endforeach
@endif

<div class="page-break"></div>
<h2>BAB III<br>PERMASALAHAN DAN KENDALA</h2>
<h3>A. Permasalahan dan Kendala</h3>
@foreach($days as $day)
    <p><strong>{{ $day['date']->translatedFormat('l, d F Y') }}:</strong> {{ $day['report']?->obstacles ?: 'Belum dicatat' }}</p>
@endforeach
<h3>B. Penanganan Permasalahan / Evaluasi</h3>
@foreach($days as $day)
    <p><strong>{{ $day['date']->translatedFormat('l, d F Y') }}:</strong> {{ $day['report']?->evaluation ?: 'Belum dicatat' }}</p>
@endforeach
<h3>C. Rencana Tindak Lanjut</h3>
@foreach($days as $day)
    <p><strong>{{ $day['date']->translatedFormat('l, d F Y') }}:</strong> {{ $day['report']?->follow_up ?: 'Belum dicatat' }}</p>
@endforeach

<div class="page-break"></div>
<h2>BAB IV<br>PENUTUP</h2>
<p class="narrative">Laporan pelaksanaan Program Makan Bergizi Gratis ini memuat data operasional yang tersedia pada SPPG selama {{ $start->translatedFormat('d F Y') }} sampai {{ $end->translatedFormat('d F Y') }}. Pelaksanaan, kendala, evaluasi, dan tindak lanjut disajikan berdasarkan catatan yang telah diinput. Bagian yang tidak memiliki sumber data ditandai sebagai belum tersedia dan memerlukan kelengkapan sebelum laporan digunakan sebagai dokumen pertanggungjawaban.</p>
@if(!$fully_approved)
    <p class="status">DRAF — satu atau lebih laporan harian belum disetujui Kepala SPPG. PDF ini belum merupakan bukti pengesahan.</p>
@endif
<table class="signatures">
    <tr>
        <td>Disusun oleh,<br>Asisten Lapangan<div class="sign-space"></div>__________________________</td>
        <td>Tempat, {{ $end->translatedFormat('d F Y') }}<br>Kepala SPPG<div class="sign-space"></div><strong>{{ $unit->head_name ?: '__________________________' }}</strong></td>
    </tr>
</table>
<p class="footnote center" style="margin-top:35px">Dokumen dihasilkan dari sistem SPPG pada {{ now()->translatedFormat('d F Y H:i') }}. Nama yang tercetak tidak menggantikan tanda tangan/pengesahan resmi.</p>
</body>
</html>
