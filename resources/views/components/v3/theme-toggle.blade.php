@props(['class' => ''])

<button
    type="button"
    data-theme-toggle
    onclick="window.sppgTheme.toggle()"
    aria-label="Ubah tema tampilan"
    title="Ubah tema tampilan"
    {{ $attributes->merge(['class' => "theme-toggle grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 {$class}"]) }}
>
    <span class="theme-icon-light" aria-hidden="true">
        <x-v3.icon name="moon" class="size-[18px]" />
    </span>
    <span class="theme-icon-dark" aria-hidden="true">
        <x-v3.icon name="sun" class="size-[18px]" />
    </span>
</button>
