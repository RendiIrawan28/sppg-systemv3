<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Serah Terima Limbah</title>
    <style>
        @page { margin: 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0; text-align: center; }
        h2 { font-size: 13px; margin: 4px 0 18px; text-align: center; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .items th, .items td { border: 1px solid #333; padding: 6px; vertical-align: top; }
        .items th { background: #eee; }
        .party { margin-top: 16px; }
        .party td { width: 50%; padding: 8px; vertical-align: top; border: 1px solid #999; }
        .signature { margin-top: 38px; }
        .signature td { width: 50%; text-align: center; vertical-align: top; }
        .name-line { margin-top: 58px; text-decoration: underline; font-weight: bold; }
        .muted { color: #555; }
        .right { text-align: right; }
        .center { text-align: center; }
    </style>
</head>
<body>
    <h1>BERITA ACARA SERAH TERIMA LIMBAH</h1>
    <h2>DIVISI PERSIAPAN</h2>

    <table class="meta">
        <tr><td style="width: 25%">Unit SPPG</td><td>: {{ $report->sppgUnit?->name }}</td></tr>
        <tr><td>Nomor BA</td><td>: {{ $report->report_number }}</td></tr>
        <tr><td>Tanggal</td><td>: {{ $report->report_date?->translatedFormat('d F Y') }}</td></tr>
        <tr><td>Nama Petugas</td><td>: {{ $report->petugas_name_snapshot ?: $report->petugas?->name }}</td></tr>
    </table>

    <table class="party">
        <tr>
            <td>
                <strong>PIHAK PERTAMA</strong><br><br>
                Nama: {{ $report->first_party_name }}<br>
                Jabatan: {{ $report->first_party_position ?: '-' }}<br>
                Alamat: {{ $report->first_party_address ?: '-' }}
            </td>
            <td>
                <strong>PIHAK KEDUA</strong><br><br>
                Nama: {{ $report->second_party_name }}<br>
                Jabatan: {{ $report->second_party_position ?: '-' }}<br>
                Alamat: {{ $report->second_party_address ?: '-' }}
            </td>
        </tr>
    </table>

    <p>
        Pada tanggal tersebut di atas, PIHAK PERTAMA telah menyerahkan dan PIHAK KEDUA
        telah menerima limbah dengan rincian sebagai berikut:
    </p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 7%">No.</th>
                <th>Jenis Limbah</th>
                <th style="width: 18%">Berat</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->items as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $item->waste_type }}</td>
                    <td class="right">{{ number_format((float) $item->weight_kg, 3, ',', '.') }} kg</td>
                    <td>{{ $item->notes ?: '-' }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2"><strong>Total</strong></td>
                <td class="right"><strong>{{ number_format($report->total_weight_kg, 3, ',', '.') }} kg</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @if ($report->notes)
        <p><strong>Catatan:</strong> {{ $report->notes }}</p>
    @endif

    <p>
        Demikian berita acara ini dibuat untuk dipergunakan sebagaimana mestinya.
    </p>

    <table class="signature">
        <tr>
            <td>
                PIHAK PERTAMA
                <div class="name-line">{{ $report->first_party_name }}</div>
                <div>{{ $report->first_party_position }}</div>
            </td>
            <td>
                PIHAK KEDUA
                <div class="name-line">{{ $report->second_party_name }}</div>
                <div>{{ $report->second_party_position }}</div>
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 28px">
        Status: {{ $report->status?->label() }}
        @if ($report->verified_at)
            — Diverifikasi {{ $report->verified_at->format('d-m-Y H:i') }}
            oleh {{ $report->verifier?->name }}
        @endif
    </p>
</body>
</html>
