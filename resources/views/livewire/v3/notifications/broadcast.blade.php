<x-v3.shell :$unit :$navigation :$roleLabel title="Kirim Notifikasi" eyebrow="Informasi untuk seluruh karyawan">
    <div class="mx-auto max-w-[1100px] space-y-5">
        <section class="overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-8">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-cyan-300">Pengumuman SPPG</p>
            <div class="mt-3 flex flex-col justify-between gap-4 md:flex-row md:items-end">
                <div>
                    <h2 class="text-2xl font-bold">Kirim informasi ke semua akun</h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">Pesan masuk ke riwayat notifikasi setiap akun aktif. Perangkat Android yang terdaftar juga menerima pemberitahuan langsung.</p>
                </div>
                <div class="shrink-0 rounded-2xl border border-white/10 bg-white/[.07] px-5 py-4">
                    <p class="text-xs text-slate-400">Penerima</p>
                    <p class="mt-1 text-2xl font-bold">{{ number_format($activeAccounts, 0, ',', '.') }} akun aktif</p>
                </div>
            </div>
        </section>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @if($lastResult)
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">Total akun</p><p class="mt-1 text-2xl font-bold text-slate-700">{{ $lastResult['recipients'] }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">Terkirim ke perangkat</p><p class="mt-1 text-2xl font-bold text-emerald-700">{{ $lastResult['sent'] }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">Tanpa perangkat</p><p class="mt-1 text-2xl font-bold text-amber-700">{{ $lastResult['no_device'] }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs font-semibold text-slate-500">Gagal</p><p class="mt-1 text-2xl font-bold text-rose-700">{{ $lastResult['failed'] }}</p></div>
            </section>
        @endif

        <form wire:submit="send" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div><h3 class="text-lg font-bold text-slate-900">Tulis notifikasi</h3><p class="mt-1 text-sm text-slate-500">Gunakan pesan singkat dan jelas agar mudah dibaca dari layar ponsel.</p></div>
            <div class="mt-5 space-y-4">
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Judul *</span><input wire:model="title" type="text" maxlength="120" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Contoh: Perubahan jadwal operasional">@error('title')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Isi pesan *</span><textarea wire:model="message" rows="5" maxlength="500" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm" placeholder="Tulis informasi yang perlu diketahui seluruh karyawan"></textarea>@error('message')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
            </div>
            <div class="mt-5 flex justify-end"><button type="submit" wire:loading.attr="disabled" wire:confirm="Kirim notifikasi ini ke seluruh akun aktif?" class="inline-flex h-11 items-center justify-center rounded-xl bg-sky-600 px-5 text-sm font-bold text-white disabled:opacity-60"><span wire:loading.remove wire:target="send">Kirim ke semua akun</span><span wire:loading wire:target="send">Sedang mengirim...</span></button></div>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h3 class="text-lg font-bold text-slate-900">Riwayat pengiriman</h3>
            <div class="mt-4 space-y-3">
                @forelse($recentBroadcasts as $broadcast)
                    <article class="rounded-xl border border-slate-200 p-4">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><p class="font-bold text-slate-900">{{ $broadcast['title'] }}</p><p class="mt-1 text-sm text-slate-600">{{ $broadcast['body'] }}</p><p class="mt-2 text-xs text-slate-400">{{ $broadcast['sender'] }} · {{ $broadcast['created_at']?->translatedFormat('d M Y, H:i') }}</p></div><div class="flex shrink-0 flex-wrap gap-2 text-[11px] font-bold"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700">{{ $broadcast['sent'] }} terkirim</span><span class="rounded-full bg-amber-50 px-2.5 py-1 text-amber-700">{{ $broadcast['no_device'] }} tanpa perangkat</span>@if($broadcast['failed'] > 0)<span class="rounded-full bg-rose-50 px-2.5 py-1 text-rose-700">{{ $broadcast['failed'] }} gagal</span>@endif</div></div>
                    </article>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Belum ada notifikasi yang dikirim melalui website.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-v3.shell>
