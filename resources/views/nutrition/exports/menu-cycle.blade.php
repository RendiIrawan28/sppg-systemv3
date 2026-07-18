<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 17px; margin: 0 0 4px; }
        .meta { margin-bottom: 14px; line-height: 1.6; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 6px; vertical-align: top; }
        th { background: #e5e7eb; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Siklus Menu</h1>
    <div class="meta">
        <strong>Unit:</strong> {{ $cycle->sppgUnit?->name }}<br>
        <strong>Kode:</strong> {{ $cycle->code }} &nbsp; <strong>Nama:</strong> {{ $cycle->name }}<br>
        <strong>Mulai:</strong> {{ $cycle->start_date?->format('d-m-Y') }} &nbsp; <strong>Panjang:</strong> {{ $cycle->cycle_length_days }} hari
    </div>
    <table>
        <thead><tr><th>No</th><th>Hari Ke-</th><th>Tanggal</th><th>Kode Menu</th><th>Menu</th><th>Catatan</th></tr></thead>
        <tbody>
        @foreach ($cycle->days as $index => $day)
            <tr>
                <td class="right">{{ $index + 1 }}</td>
                <td class="right">{{ $day->day_number }}</td>
                <td>{{ $day->service_date?->format('d-m-Y') }}</td>
                <td>{{ $day->menu?->code }}</td>
                <td>{{ $day->menu?->name ?? 'Belum dipilih' }}</td>
                <td>{{ $day->notes }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
