@props(['name', 'class' => 'size-5'])

@switch($name)
    @case('home')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m3 10.5 9-7.5 9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5v-9Z"/><path stroke-linecap="round" d="M9 21v-7.5h6V21"/></svg>
        @break
    @case('users')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87m-3-11.96a4 4 0 0 1 0 7.75"/></svg>
        @break
    @case('arrow-up-right')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M7 7h10v10"/></svg>
        @break
    @case('menu')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '2']) }}><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
        @break
    @case('search')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/></svg>
        @break
    @case('chevron-down')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '2']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
        @break
    @case('logout')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M10 17l5-5-5-5m5 5H3m12-9h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>
        @break
    @case('sun')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
        @break
    @case('moon')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        @break
    @case('clipboard')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><rect x="5" y="4" width="14" height="17" rx="2"/><path stroke-linecap="round" d="M9 4.5V3h6v1.5M9 9h6m-6 4h6m-6 4h4"/></svg>
        @break
    @case('cart')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 7H6m4 13h.01M18 20h.01"/></svg>
        @break
    @case('check-badge')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 2.2 1.5 2.7-.2.8 2.6 2.2 1.6-.9 2.5.9 2.5-2.2 1.6-.8 2.6-2.7-.2L12 21l-2.2-1.5-2.7.2-.8-2.6L4.1 15.5 5 13l-.9-2.5 2.2-1.6.8-2.6 2.7.2L12 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4"/></svg>
        @break
    @case('alert')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.8 2.4 18a2 2 0 0 0 1.8 3h15.6a2 2 0 0 0 1.8-3L13.7 3.8a2 2 0 0 0-3.4 0Z"/><path stroke-linecap="round" d="M12 9v4m0 4h.01"/></svg>
        @break
    @case('box')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m21 8-9-5-9 5 9 5 9-5Zm-18 0v8l9 5 9-5V8m-9 5v8"/></svg>
        @break
    @case('calendar')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M8 3v4m8-4v4M3 10h18"/></svg>
        @break
    @case('pencil')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m14 5 5 5M4 20l3.5-.7L19 7.8a2.1 2.1 0 0 0-3-3L4.5 16.3 4 20Z"/></svg>
        @break
    @case('upload')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V3m0 0L7 8m5-5 5 5M5 14v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/></svg>
        @break
    @case('arrow-left')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6M9 12h12"/></svg>
        @break
    @case('trash')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M9 7l1-3h4l1 3m3 0-1 14H7L6 7"/></svg>
        @break
    @case('calculator')
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><rect x="4" y="2" width="16" height="20" rx="2"/><path stroke-linecap="round" d="M8 6h8v4H8V6Zm0 8h.01m4 0h.01m4 0h.01M8 18h.01m4 0h.01m4 0h.01"/></svg>
        @break
    @default
        <svg {{ $attributes->merge(['class' => $class, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8']) }}><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v8m-4-4h8"/></svg>
@endswitch
