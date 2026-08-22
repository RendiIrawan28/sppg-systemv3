@props(['includeErrors' => false])

@php
    $alerts = collect([
        ['type' => 'success', 'message' => session('v3.status')],
        ['type' => 'success', 'message' => session('success')],
        ['type' => 'success', 'message' => session('status')],
        ['type' => 'warning', 'message' => session('warning')],
        ['type' => 'error', 'message' => session('error')],
    ])->filter(fn (array $alert): bool => filled($alert['message']));

    if ($includeErrors && $errors->any()) {
        $alerts->push([
            'type' => 'error',
            'message' => $errors->first(),
        ]);
    }
@endphp

@foreach ($alerts->unique(fn (array $alert): string => $alert['type'].'|'.$alert['message']) as $alert)
    <span
        hidden
        aria-hidden="true"
        data-sppg-alert
        data-type="{{ $alert['type'] }}"
        data-message="{{ $alert['message'] }}"
    ></span>
@endforeach
