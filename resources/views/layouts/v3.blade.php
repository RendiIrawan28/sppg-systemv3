<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SPPG V3' }} · {{ config('app.name', 'SPPG') }}</title>
    <meta name="theme-color" content="#081d3a">
    <script>
        (() => {
            const storageKey = 'sppg-theme';
            const root = document.documentElement;
            const systemTheme = () => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

            const applyTheme = (theme) => {
                const normalized = theme === 'dark' ? 'dark' : 'light';
                root.classList.toggle('dark', normalized === 'dark');
                root.dataset.theme = normalized;
                root.style.colorScheme = normalized;

                document.querySelector('meta[name="theme-color"]')
                    ?.setAttribute('content', normalized === 'dark' ? '#07111f' : '#f4f7fb');

                document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
                    const label = normalized === 'dark' ? 'Ubah ke mode terang' : 'Ubah ke mode gelap';
                    button.setAttribute('aria-label', label);
                    button.setAttribute('title', label);
                });
            };

            window.sppgTheme = {
                apply: applyTheme,
                toggle() {
                    const next = root.classList.contains('dark') ? 'light' : 'dark';
                    localStorage.setItem(storageKey, next);
                    applyTheme(next);
                },
            };

            applyTheme(localStorage.getItem(storageKey) || systemTheme());

            document.addEventListener('DOMContentLoaded', () => {
                applyTheme(localStorage.getItem(storageKey) || systemTheme());
            }, { once: true });

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
                if (!localStorage.getItem(storageKey)) {
                    applyTheme(event.matches ? 'dark' : 'light');
                }
            });

            document.addEventListener('livewire:navigated', () => {
                applyTheme(localStorage.getItem(storageKey) || systemTheme());
            });
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="v3-theme min-h-screen bg-[#f4f7fb] font-sans text-slate-950 antialiased selection:bg-sky-200 selection:text-slate-950">
    {{ $slot }}
</body>
</html>
