@props([
    'url',
    'title' => 'Dokumentasi',
    'label' => 'Lihat dokumentasi',
])

<button
    type="button"
    data-documentation-url="{{ $url }}"
    data-documentation-title="{{ $title }}"
    x-on:click="$dispatch('open-documentation', { url: $el.dataset.documentationUrl, title: $el.dataset.documentationTitle })"
    {{ $attributes->class(['inline-flex items-center justify-center rounded-xl bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 transition hover:bg-sky-100']) }}
>
    {{ $label }}
</button>
