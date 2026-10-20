<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rencana Distribusi {{ $plan->plan_number }}</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { margin: 0; font-size: 17px; text-align: center; }
        h2 { margin: 4px 0 14px; font-size: 12px; text-align: center; font-weight: normal; }
        .meta { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
        .meta td { padding: 3px 5px; vertical-align: top; }
        .meta .label { width: 125px; font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #9ca3af; padding: 4px; vertical-align: top; }
        table.data th { background: #e5e7eb; font-size: 8px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .summary { margin-top: 10px; width: 48%; border-collapse: collapse; }
        .summary td { border: 1px solid #9ca3af; padding: 5px; }
        .summary .label { font-weight: bold; background: #f3f4f6; }
        .signatures { width: 100%; margin-top: 28px; text-align: center; }
        .signatures td { width: 33.33%; vertical-align: top; }
        .space { height: 55px; }
        .notes { margin-top: 12px; padding: 8px; border: 1px solid #d1d5db; }
        .muted { color: #6b7280; }
        .group-row td { background: #f8fafc; font-size: 8px; padding-top: 3px; padding-bottom: 3px; }
        .group-row .group-name { padding-left: 12px; }
        .group-missing td { background: #f9fafb; color: #6b7280; font-style: italic; }
        .group-summary { width: 100%; margin-top: 12px; border-collapse: collapse; }
        .group-summary th, .group-summary td { border: 1px solid #9ca3af; padding: 4px 6px; }
        .group-summary th { background: #e5e7eb; font-size: 8px; }
        .group-summary-title { font-size: 11px; margin: 14px 0 5px; }
        .section-note { color: #6b7280; font-size: 8px; margin-top: 4px; }
    </style>
</head>
<body>
    <h1>RENCANA DISTRIBUSI HARIAN</h1>
    <h2>{{ $plan->sppgUnit?->name ?? 'Unit SPPG' }}</h2>

    <table class="meta">
        <tr>
            <td class="label">Nomor Rencana</td><td>: {{ $plan->plan_number }}</td>
            <td class="label">Tanggal Distribusi</td><td>: {{ $plan->distribution_date?->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Layanan</td><td>: {{ $plan->service_date?->format('d M Y') ?? '-' }}</td>
            <td class="label">Tanggal Produksi</td><td>: {{ $plan->production_date?->format('d M Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Menu</td><td>: {{ $plan->menu_name_snapshot }}</td>
            <td class="label">Tanggal layanan</td><td>: {{ $plan->service_date?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Status</td><td>: {{ $plan->status?->label() }}</td>
            <td class="label">Batas Konfirmasi</td><td>: {{ $plan->confirmation_deadline_at?->format('d M Y H:i') ?? '-' }}</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>No</th>
                <th>Jenis</th>
                <th>Tujuan</th>
                <th>Alamat / PIC</th>
                <th>Rute</th>
                <th>Terdaftar</th>
                <th>Terkonfirmasi</th>
                <th>Porsi Kecil</th>
                <th>Porsi Besar</th>
                <th>Total</th>
                <th>Konfirmasi</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plan->destinations as $index => $destination)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $destination->destination_type === 'school' ? 'Sekolah' : ($destination->destination_type === 'posyandu' ? 'Posyandu' : 'Lainnya') }}</td>
                    <td>
                        <strong>{{ $destination->destination_name_snapshot }}</strong><br>
                        <span class="muted">{{ $destination->destination_code_snapshot }}</span>
                    </td>
                    <td>
                        {{ $destination->address_snapshot ?: '-' }}<br>
                        <span class="muted">PIC: {{ $destination->contact_name_snapshot ?: '-' }} / {{ $destination->contact_phone_snapshot ?: '-' }}</span>
                    </td>
                    <td>{{ $destination->route_name }}</td>
                    <td class="text-right">{{ number_format((int) $destination->registered_beneficiaries) }}</td>
                    <td class="text-right">{{ number_format((int) $destination->confirmed_beneficiaries) }}</td>
                    <td class="text-right">{{ number_format((int) $destination->small_portions) }}</td>
                    <td class="text-right">{{ number_format((int) $destination->large_portions) }}</td>
                    <td class="text-right"><strong>{{ number_format((int) $destination->total_portions) }}</strong></td>
                    <td>{{ ucfirst(str_replace('_', ' ', (string) $destination->confirmation_status)) }}</td>
                    <td>{{ $destination->special_notes ?: '-' }}</td>
                </tr>
                @forelse ($destination->recipientGroups as $group)
                    <tr class="group-row">
                        <td></td>
                        <td></td>
                        <td colspan="3" class="group-name">
                            <strong>{{ \App\Support\FieldDistributionPlanGroupExport::name($group) }}</strong>
                            <span class="muted">(Porsi {{ \App\Support\FieldDistributionPlanGroupExport::portionLabel($group) }})</span>
                        </td>
                        <td class="text-right">{{ number_format((int) $group->registered_beneficiaries) }}</td>
                        <td class="text-right">{{ number_format((int) $group->confirmed_beneficiaries) }}</td>
                        <td class="text-right">{{ number_format((int) $group->small_portions) }}</td>
                        <td class="text-right">{{ number_format((int) $group->large_portions) }}</td>
                        <td class="text-right">{{ number_format((int) $group->total_portions) }}</td>
                        <td class="muted">Per kelompok</td>
                        <td>{{ $group->notes ?: '-' }}</td>
                    </tr>
                @empty
                    <tr class="group-missing">
                        <td colspan="12">Rincian kelompok untuk {{ $destination->destination_name_snapshot }} belum tersedia pada rencana ini.</td>
                    </tr>
                @endforelse
            @empty
                <tr><td colspan="12" class="text-center">Belum ada tujuan distribusi.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td class="label">Jumlah Tujuan</td><td class="text-right">{{ number_format((int) $plan->destination_count) }}</td></tr>
        <tr><td class="label">Penerima Terdaftar</td><td class="text-right">{{ number_format((int) $plan->planned_beneficiaries) }}</td></tr>
        <tr><td class="label">Penerima Terkonfirmasi</td><td class="text-right">{{ number_format((int) $plan->confirmed_beneficiaries) }}</td></tr>
        <tr><td class="label">Porsi Kecil</td><td class="text-right">{{ number_format((int) $plan->planned_small_portions) }}</td></tr>
        <tr><td class="label">Porsi Besar</td><td class="text-right">{{ number_format((int) $plan->planned_large_portions) }}</td></tr>
        <tr><td class="label">Total Porsi</td><td class="text-right"><strong>{{ number_format((int) $plan->planned_total_portions) }}</strong></td></tr>
    </table>

    @php
        $groupTotals = \App\Support\FieldDistributionPlanGroupExport::totalsByGroup($plan);
    @endphp
    @if ($groupTotals->isNotEmpty())
        <h3 class="group-summary-title">REKAP PORSI PER KELOMPOK PENERIMA</h3>
        <table class="group-summary">
            <thead>
                <tr>
                    <th>Kelompok</th>
                    <th>Ukuran Porsi</th>
                    <th>Terdaftar</th>
                    <th>Terkonfirmasi</th>
                    <th>Porsi Kecil</th>
                    <th>Porsi Besar</th>
                    <th>Total Porsi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($groupTotals as $groupTotal)
                    <tr>
                        <td>{{ $groupTotal['name'] }}</td>
                        <td class="text-center">{{ $groupTotal['portion_label'] }}</td>
                        <td class="text-right">{{ number_format($groupTotal['registered']) }}</td>
                        <td class="text-right">{{ number_format($groupTotal['confirmed']) }}</td>
                        <td class="text-right">{{ number_format($groupTotal['small']) }}</td>
                        <td class="text-right">{{ number_format($groupTotal['large']) }}</td>
                        <td class="text-right"><strong>{{ number_format($groupTotal['total']) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="section-note">Rekap dihitung dari rincian kelompok yang tersimpan dalam rencana distribusi, bukan estimasi dari porsi kecil/besar.</p>
    @endif

    @if ($plan->general_notes)
        <div class="notes"><strong>Catatan Umum:</strong><br>{{ $plan->general_notes }}</div>
    @endif

    <table class="signatures">
        <tr>
            <td>Disusun oleh,<br>Asisten Lapangan<div class="space"></div><strong>{{ $plan->creator?->name ?? '________________' }}</strong></td>
            <td>Disetujui oleh,<br>Kepala SPPG<div class="space"></div><strong>{{ $plan->approver?->name ?? '________________' }}</strong></td>
        </tr>
    </table>
</body>
</html>
