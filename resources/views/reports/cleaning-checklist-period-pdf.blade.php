<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
@page{margin:16px 18px}body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#111}h1{font-size:14px;text-align:center;margin:0}h2{font-size:12px;text-align:center;margin:3px 0 14px}.meta{margin-bottom:8px;font-size:10px}.check{width:100%;border-collapse:collapse;table-layout:fixed}.check th,.check td{border:1px solid #333;padding:4px;text-align:center;vertical-align:middle}.check th.item,.check td.item{text-align:left;width:190px}.check th.no,.check td.no{width:26px}.check th{background:#eee;font-weight:700}.day{font-size:8px}.tick{font-size:14px;font-weight:bold}.evaluation{margin-top:10px;border:1px solid #333;min-height:82px;padding:7px}.small{font-size:8px;font-style:italic;margin-top:4px}.footer{margin-top:6px;font-size:8px;color:#444}
</style></head><body>
@php($formTitle = match(App\Support\CleaningChecklistTemplate::forArea($area)) {
    App\Support\CleaningChecklistTemplate::TOILET => 'TOILET',
    App\Support\CleaningChecklistTemplate::PRODUCTION => 'AREA PRODUKSI',
    App\Support\CleaningChecklistTemplate::PORTIONING => 'AREA PEMORSIAN',
    App\Support\CleaningChecklistTemplate::WAREHOUSE => 'GUDANG',
    default => strtoupper($area->name),
})
<h1>FORM CHECKLIST KEBERSIHAN {{ $formTitle }}</h1>
<h2>{{ strtoupper($unit?->name ?? 'SATUAN PELAYANAN PEMENUHAN GIZI') }}</h2>
<table class="meta"><tr><td style="width:80px"><strong>{{ App\Support\CleaningChecklistTemplate::areaIdentityLabel($area) }}</strong></td><td>: {{ $area->name }}</td></tr><tr><td><strong>Periode</strong></td><td>: {{ $periodLabel }}</td></tr></table>
<table class="check"><thead><tr><th rowspan="3" class="no">No</th><th rowspan="3" class="item">Item Kebersihan</th><th colspan="{{ max(1,$days->count()) }}">Hari, Tanggal</th></tr><tr>@foreach($days as $day)<th class="day">{{ $day->translatedFormat('l') }}</th>@endforeach</tr><tr>@foreach($days as $day)<th class="day">{{ $day->format('d/m/Y') }}</th>@endforeach</tr></thead><tbody>
@foreach($items as $index=>$definition)<tr><td class="no">{{ $index+1 }}.</td><td class="item">{{ $definition['item_name'] }}</td>@foreach($days as $day)@php($session=$sessions->get($day->toDateString()))@php($item=$session?->checklistItems?->firstWhere('item_name',$definition['item_name']))<td class="tick">{{ $item?->result === 'pass' ? '✓' : '' }}</td>@endforeach</tr>@endforeach
</tbody></table><div class="small">(*isi dengan tanda centang jika item kebersihan terpenuhi)</div>
<div class="evaluation"><strong>Evaluasi kebersihan:</strong><br>@foreach($days as $day)@php($session=$sessions->get($day->toDateString()))@if($session && (filled($session->after_condition)||$session->checklistItems->contains(fn($i)=>$i->result==='fail')))<strong>{{ $day->format('d/m/Y') }}:</strong> {{ $session->after_condition }} @foreach($session->checklistItems->where('result','fail') as $failed){{ $failed->item_name }}{{ $failed->notes ? ' - '.$failed->notes : '' }}; @endforeach<br>@endif @endforeach</div>
<div class="footer">Dicetak dari SPPG System · {{ now()->format('d/m/Y H:i') }}</div></body></html>
