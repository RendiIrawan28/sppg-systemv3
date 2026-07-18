<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 26px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1, h2 { text-align: center; margin: 3px 0; }
        h3 { margin: 12px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #555; padding: 4px; vertical-align: top; }
        th { background: #e8eef5; }
        .meta td { border: 0; padding: 2px 4px; }
        .summary-box td { text-align: center; font-weight: bold; font-size: 10px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .muted { color: #555; font-size: 8px; }
        .signature { margin-top: 22px; }
        .no-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>LAPORAN RINGKASAN MASTER PENERIMA MANFAAT</h1>
    <h2>{{ $period->sppgUnit?->name }} — BADAN GIZI NASIONAL</h2>

    <table class="meta">
        <tr>
            <td>Nomor Dokumen</td>
            <td>: {{ $period->document_number ?: '-' }}</td>
            <td>Periode</td>
            <td>: {{ $period->start_date?->format('d M Y') }} s.d. {{ $period->end_date?->format('d M Y') }}</td>
        </tr>
        <tr>
            <td>Status</td>
            <td>: {{ $period->statusLabel() }}</td>
            <td>Revisi</td>
            <td>: {{ $period->revision_number }}</td>
        </tr>
    </table>

    <table class="summary-box">
        <tr>
            <th>Jumlah Instansi</th>
            <th>Penerima Aktif</th>
            <th>Porsi Kecil</th>
            <th>Porsi Besar</th>
        </tr>
        <tr>
            <td>{{ number_format($period->destination_count) }}</td>
            <td>{{ number_format((int) data_get($recap, 'totals.total', 0)) }}</td>
            <td>{{ number_format((int) data_get($recap, 'totals.small', 0)) }}</td>
            <td>{{ number_format((int) data_get($recap, 'totals.large', 0)) }}</td>
        </tr>
    </table>

    <h3>Rekap per Instansi</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 4%">No</th>
                <th style="width: 8%">Jenis</th>
                <th style="width: 9%">Kode</th>
                <th style="width: 23%">Instansi</th>
                <th>Kelompok Penerima</th>
                <th style="width: 7%">Kecil</th>
                <th style="width: 7%">Besar</th>
                <th style="width: 7%">Total</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($recap['destinations'] as $index => $destination)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td>{{ $destination['type'] }}</td>
                <td>{{ $destination['code'] ?: '-' }}</td>
                <td>{{ $destination['name'] }}</td>
                <td>
                    @forelse ($destination['groups'] as $name => $count)
                        {{ $name }}: {{ number_format($count) }}<br>
                    @empty
                        -
                    @endforelse
                </td>
                <td class="right">{{ number_format((int) ($destination['small'] ?? 0)) }}</td>
                <td class="right">{{ number_format((int) ($destination['large'] ?? 0)) }}</td>
                <td class="right">{{ number_format((int) ($destination['total'] ?? 0)) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="center">Belum ada data instansi.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="no-break">
        <h3>Rekap Kelompok Penerima</h3>
        <table>
            <thead><tr><th>Kelompok</th><th style="width: 20%">Jumlah</th></tr></thead>
            <tbody>
            @forelse ($recap['groups'] as $group => $count)
                <tr>
                    <td>{{ $group ?: 'Tanpa Kelompok' }}</td>
                    <td class="right">{{ number_format($count) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="center">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="no-break">
        <h3>Rekap Kelompok Menu dan Kategori Porsi</h3>
        <table>
            <thead>
                <tr>
                    <th>Kelompok Menu</th>
                    <th style="width: 18%">Porsi Kecil</th>
                    <th style="width: 18%">Porsi Besar</th>
                    <th style="width: 18%">Total</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($recap['menus'] as $menu => $values)
                <tr>
                    <td>{{ match ($menu) { 'student' => 'Siswa', 'toddler' => 'Balita', 'pregnant_mother' => 'Ibu Hamil', 'breastfeeding_mother' => 'Ibu Menyusui', default => \Illuminate\Support\Str::of((string) $menu)->replace('_', ' ')->title() } }}</td>
                    <td class="right">{{ number_format((int) ($values['small'] ?? 0)) }}</td>
                    <td class="right">{{ number_format((int) ($values['large'] ?? 0)) }}</td>
                    <td class="right">{{ number_format((int) ($values['total'] ?? 0)) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="center">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3>Histori Persetujuan dan Revisi</h3>
    <table>
        <thead>
            <tr>
                <th style="width: 16%">Waktu</th>
                <th style="width: 18%">Pengguna</th>
                <th style="width: 14%">Aksi</th>
                <th style="width: 10%">Dari</th>
                <th style="width: 10%">Ke</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($period->histories as $history)
            <tr>
                <td>{{ $history->created_at?->format('d-m-Y H:i') }}</td>
                <td>{{ $history->user?->name ?: 'Sistem' }}</td>
                <td>{{ $history->action }}</td>
                <td>{{ $history->from_status ?: '-' }}</td>
                <td>{{ $history->to_status ?: '-' }}</td>
                <td>{{ $history->notes ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center">Belum ada histori.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p class="muted">
        Daftar nama penerima manfaat secara lengkap tersedia pada file Excel laporan periode ini.
    </p>

    <table class="meta signature">
        <tr>
            <td style="width: 65%"></td>
            <td class="center">
                Kepala SPPG<br><br><br><br>
                <strong>{{ $period->approver?->name ?: '____________________' }}</strong><br>
                {{ $period->approved_at?->format('d M Y') }}
            </td>
        </tr>
    </table>
</body>
</html>
