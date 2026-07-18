<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#111827}
        h1{font-size:16px;margin:0 0 4px}
        h2{font-size:11px;margin:12px 0 5px}
        .meta{line-height:1.6;margin-bottom:12px}
        table{width:100%;border-collapse:collapse;margin-bottom:10px}
        th,td{border:1px solid #9ca3af;padding:5px;vertical-align:top}
        th{background:#e5e7eb}
        .num{text-align:right}
        .small{font-size:8px;line-height:1.35}
    </style>
</head>
<body>
<h1>Rencana Kebutuhan Bahan</h1>
<div class="meta">
    <strong>Unit:</strong> {{ $plan->sppgUnit?->name }}<br>
    <strong>Nomor:</strong> {{ $plan->plan_number }} &nbsp;
    <strong>Tanggal:</strong> {{ $plan->requirement_date?->format('d-m-Y') }}<br>
    <strong>Menu:</strong> {{ $plan->menu?->name }} &nbsp;
    <strong>Porsi Aktual:</strong> {{ number_format($plan->total_portions,0,',','.') }} &nbsp;
    <strong>Porsi Efektif:</strong> {{ number_format((float)$plan->effective_portions,2,',','.') }} &nbsp;
    <strong>Buffer:</strong> {{ $plan->buffer_percent }}% &nbsp;
    <strong>Total:</strong> {{ $plan->total_weight_kg }} kg
</div>

<h2>Rincian Porsi per Kelompok</h2>
<table>
    <thead>
    <tr>
        <th>Kelompok</th>
        <th>Kelompok Menu</th>
        <th>Kategori Porsi</th>
        <th>Porsi Aktual</th>
        <th>Pengali</th>
        <th>Porsi Efektif</th>
    </tr>
    </thead>
    <tbody>
    @forelse(($plan->portion_breakdown ?? []) as $allocation)
        <tr>
            <td>{{ $allocation['name'] ?? $allocation['code'] ?? '-' }}</td>
            <td>{{ strtoupper(str_replace('_', ' ', $allocation['menu_audience'] ?? '-')) }}</td>
            <td>{{ strtoupper($allocation['portion_size'] ?? '-') }}</td>
            <td class="num">{{ number_format((float)($allocation['actual_portions'] ?? 0),0,',','.') }}</td>
            <td class="num">{{ number_format((float)($allocation['portion_multiplier'] ?? 1),2,',','.') }}</td>
            <td class="num">{{ number_format((float)($allocation['effective_portions'] ?? 0),2,',','.') }}</td>
        </tr>
    @empty
        <tr><td colspan="6">Rincian porsi belum dihitung.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Daftar Kebutuhan Bahan</h2>
<table>
    <thead>
    <tr>
        <th>No</th>
        <th>Kode</th>
        <th>Bahan</th>
        <th>Hidangan</th>
        <th>g/Porsi Standar</th>
        <th>Porsi Efektif</th>
        <th>Dasar (g)</th>
        <th>Buffer</th>
        <th>Total (g)</th>
        <th>Total (kg)</th>
        <th>BDD</th>
        <th>Rincian</th>
    </tr>
    </thead>
    <tbody>
    @foreach($plan->items as $index=>$item)
        <tr>
            <td class="num">{{ $index+1 }}</td>
            <td>{{ $item->ingredient_code_snapshot }}</td>
            <td>{{ $item->ingredient_name_snapshot }}</td>
            <td>{{ $item->recipe_components }}</td>
            <td class="num">{{ number_format((float)$item->quantity_per_portion_grams,2,',','.') }}</td>
            <td class="num">{{ number_format((float)$item->effective_portions,2,',','.') }}</td>
            <td class="num">{{ number_format((float)$item->base_quantity_grams,2,',','.') }}</td>
            <td class="num">{{ $item->buffer_percent }}%</td>
            <td class="num">{{ number_format((float)$item->total_quantity_grams,2,',','.') }}</td>
            <td class="num">{{ number_format((float)$item->total_quantity_kg,3,',','.') }}</td>
            <td class="num">{{ $item->edible_portion_percent }}%</td>
            <td class="small">{!! nl2br(e($item->calculation_breakdown_summary)) !!}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
