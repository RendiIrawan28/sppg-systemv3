<x-v3.shell :$unit :$navigation :$roleLabel title="Pengambilan Ompreng" eyebrow="Kegiatan siang hari">
    <div
        x-data="{
            photoModalOpen: false,
            photoModalUrl: '',
            photoModalTitle: ''
        }"
        x-on:keydown.escape.window="photoModalOpen = false"
        class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100"
    >
        <style>[x-cloak] { display: none !important; }</style>

        <x-v3.flash-alert />

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-cyan-200">Daftar otomatis dari pengantaran</p>
            <h2 class="mt-2 text-2xl font-bold">Ambil ompreng setelah penerima selesai makan</h2>
            <p class="mt-2 max-w-4xl text-sm text-slate-300">
                Sekolah dan Posyandu muncul otomatis setelah makanan berhasil diantar. Driver pengambil tidak harus sama dengan driver pengantar.
            </p>
        </section>

        @if(!$activeRun && $canOperate)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <h3 class="font-bold">Mulai kegiatan pengambilan</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Nama driver otomatis mengikuti akun yang login. Data kendaraan dapat diisi bila diperlukan.
                </p>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <input wire:model="kernetName"
                           class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                           placeholder="Nama kernet (opsional)">

                    <input wire:model="vehicleName"
                           class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                           placeholder="Kendaraan (opsional)">

                    <input wire:model="vehiclePlate"
                           class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm uppercase dark:border-slate-700 dark:bg-slate-950"
                           placeholder="Nomor polisi (opsional)">

                    <input wire:model="runNotes"
                           class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                           placeholder="Catatan kegiatan">
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="button"
                            wire:click="startRun"
                            class="h-11 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white hover:bg-sky-700">
                        Mulai pengambilan
                    </button>
                </div>
            </section>
        @endif

        @if($activeRun)
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-400/25 dark:bg-sky-500/10">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700 dark:text-sky-300">
                            Kegiatan aktif {{ $activeRun->run_number }}
                        </p>
                        <h3 class="mt-1 text-lg font-bold text-sky-950 dark:text-sky-100">
                            Driver {{ $activeRun->driver_name_snapshot }}
                        </h3>
                        <p class="mt-1 text-xs text-sky-700 dark:text-sky-300">
                            Sudah dibawa: {{ number_format($activeRun->total_collected, 0, ',', '.') }} ompreng
                            dari {{ $activeRun->items->count() }} pencatatan.
                        </p>
                    </div>

                    <button type="button"
                            wire:click="returnToSppg"
                            wire:confirm="Pastikan seluruh ompreng yang sudah diambil berada di kendaraan. Kembali ke SPPG sekarang?"
                            class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white hover:bg-emerald-700">
                        Kembali ke SPPG
                    </button>
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold">Menunggu pengambilan</h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Urutan pengambilan ditentukan driver sesuai kondisi lapangan.
                    </p>
                </div>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                    {{ $tasks->count() }} tujuan
                </span>
            </div>

            <div class="mt-4 space-y-3">
                @forelse($tasks as $task)
                    <article class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="font-bold">{{ $task->destination_name }}</h4>
                                    @if($task->status === 'partial')
                                        <span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                            Diambil sebagian
                                        </span>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Diantar {{ $task->delivery_date?->format('d/m/Y') }} · {{ $task->address ?: 'Alamat belum tersedia' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Kontak: {{ $task->contact_name ?: '—' }} {{ $task->contact_phone ? '· '.$task->contact_phone : '' }}
                                </p>
                            </div>

                            <div class="rounded-xl bg-slate-100 px-4 py-3 text-right dark:bg-slate-800">
                                <p class="text-[10px] font-bold uppercase text-slate-500">Sisa target</p>
                                <p class="mt-1 text-xl font-bold">{{ number_format($task->remaining_containers, 0, ',', '.') }}</p>
                                <p class="text-[10px] text-slate-500">
                                    dari {{ number_format($task->target_containers, 0, ',', '.') }} ompreng
                                </p>
                            </div>
                        </div>

                        @if($activeRun && $canOperate)
                            <div class="mt-4 grid gap-3 lg:grid-cols-[180px_1fr_1fr_auto_auto]">
                                <input wire:model="partialQuantities.{{ $task->id }}"
                                       type="number"
                                       min="1"
                                       max="{{ $task->remaining_containers }}"
                                       class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                                       placeholder="Jumlah sebagian">

                                <input wire:model="partialNotes.{{ $task->id }}"
                                       class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"
                                       placeholder="Alasan sisa belum diambil">

                                <input wire:model="collectionPhotos.{{ $task->id }}"
                                       type="file"
                                       accept="image/*"
                                       class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950">

                                <button type="button"
                                        wire:click="collectPartial({{ $task->id }})"
                                        class="h-10 rounded-xl border border-amber-300 px-4 text-xs font-bold text-amber-700 dark:border-amber-500/40 dark:text-amber-300">
                                    Diambil sebagian
                                </button>

                                <button type="button"
                                        wire:click="collectAll({{ $task->id }})"
                                        wire:confirm="Tandai seluruh ompreng tujuan ini sudah diambil?"
                                        class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white hover:bg-emerald-700">
                                    Ompreng sudah diambil
                                </button>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">
                        Tidak ada ompreng yang menunggu diambil.
                    </div>
                @endforelse
            </div>
        </section>

        {{-- ============================================================
            RIWAYAT PENGAMBILAN
        ============================================================ --}}
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div>
                <h3 class="font-bold">Riwayat kegiatan pengambilan</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Buka detail untuk melihat sekolah/Posyandu, jumlah ompreng, waktu, foto, dan catatan setiap pengambilan.
                </p>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-[10px] font-bold uppercase tracking-[.12em] text-slate-500">
                            <th class="py-3 pr-4">Nomor</th>
                            <th class="py-3 pr-4">Driver</th>
                            <th class="py-3 pr-4">Tanggal</th>
                            <th class="py-3 pr-4">Tujuan</th>
                            <th class="py-3 pr-4">Ompreng</th>
                            <th class="py-3 pr-4">Status</th>
                            <th class="py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentRuns as $run)
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <td class="py-3 pr-4 font-bold">{{ $run->run_number }}</td>
                                <td class="py-3 pr-4">{{ $run->driver_name_snapshot }}</td>
                                <td class="py-3 pr-4">{{ $run->collection_date?->format('d/m/Y') }}</td>
                                <td class="py-3 pr-4">{{ $run->items_count }}</td>
                                <td class="py-3 pr-4">{{ number_format($run->total_collected, 0, ',', '.') }}</td>
                                <td class="py-3 pr-4">
                                    <span class="font-bold {{ $run->state === 'returned' ? 'text-emerald-600' : 'text-sky-600' }}">
                                        {{ $run->state === 'returned' ? 'Kembali ke SPPG' : 'Aktif' }}
                                    </span>
                                </td>
                                <td class="py-3 text-right">
                                    <button type="button"
                                            wire:click="showRunDetail({{ $run->id }})"
                                            class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-[11px] font-bold text-sky-700 hover:bg-sky-100 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-300">
                                        Lihat detail
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-500">
                                    Belum ada riwayat pengambilan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ============================================================
            DETAIL RIWAYAT
        ============================================================ --}}
        @if($selectedRun)
            @php
                $uniqueTasks = $selectedRun->items
                    ->filter(fn ($item) => $item->task)
                    ->unique('container_collection_task_id');

                $totalTarget = (int) $uniqueTasks->sum(fn ($item) => (int) $item->task->target_containers);

                $lastItemPerTask = $selectedRun->items
                    ->groupBy('container_collection_task_id')
                    ->map(fn ($items) => $items->sortByDesc('id')->first());

                $remainingAfterRun = (int) $lastItemPerTask->sum(
                    fn ($item) => (int) ($item->remaining_after_collection ?? 0)
                );
            @endphp

            <section id="detail-pengambilan-ompreng"
                     class="rounded-2xl border border-sky-200 bg-white p-5 shadow-sm dark:border-sky-400/30 dark:bg-slate-900 dark:shadow-none">
                <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-sky-600 dark:text-sky-300">
                            Detail Pengambilan Ompreng
                        </p>
                        <h3 class="mt-1 text-xl font-bold">{{ $selectedRun->run_number }}</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ $selectedRun->collection_date?->format('d/m/Y') }} · Driver {{ $selectedRun->driver_name_snapshot }}
                        </p>
                    </div>

                    <button type="button"
                            wire:click="closeRunDetail"
                            class="h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        Tutup detail
                    </button>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Tujuan</p>
                        <p class="mt-1 text-xl font-bold">{{ $uniqueTasks->count() }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Target tujuan</p>
                        <p class="mt-1 text-xl font-bold">{{ number_format($totalTarget, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                        <p class="text-[10px] font-bold uppercase text-emerald-700 dark:text-emerald-300">Dibawa kegiatan ini</p>
                        <p class="mt-1 text-xl font-bold text-emerald-700 dark:text-emerald-300">
                            {{ number_format($selectedRun->total_collected, 0, ',', '.') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-4 dark:bg-amber-500/10">
                        <p class="text-[10px] font-bold uppercase text-amber-700 dark:text-amber-300">Sisa setelah kegiatan</p>
                        <p class="mt-1 text-xl font-bold text-amber-700 dark:text-amber-300">
                            {{ number_format($remainingAfterRun, 0, ',', '.') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800/70">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Kembali SPPG</p>
                        <p class="mt-1 text-sm font-bold">
                            {{ $selectedRun->returned_at?->format('d/m/Y H:i') ?: 'Belum kembali' }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Driver</p>
                        <p class="mt-1 text-sm font-semibold">{{ $selectedRun->driver_name_snapshot ?: '—' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Kernet</p>
                        <p class="mt-1 text-sm font-semibold">{{ $selectedRun->kernet_name ?: '—' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Kendaraan</p>
                        <p class="mt-1 text-sm font-semibold">
                            {{ $selectedRun->vehicle_name ?: '—' }}
                            {{ $selectedRun->vehicle_plate ? '· '.$selectedRun->vehicle_plate : '' }}
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Mulai</p>
                        <p class="mt-1 text-sm font-semibold">{{ $selectedRun->started_at?->format('d/m/Y H:i') ?: '—' }}</p>
                    </div>
                </div>

                @if($selectedRun->notes)
                    <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/70">
                        <p class="text-[10px] font-bold uppercase text-slate-500">Catatan kegiatan</p>
                        <p class="mt-1">{{ $selectedRun->notes }}</p>
                    </div>
                @endif

                <div class="mt-6">
                    <h4 class="font-bold">Rincian tujuan yang diambil</h4>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Satu tujuan dapat muncul lebih dari sekali apabila pengambilan dilakukan sebagian lalu dilanjutkan kembali.
                    </p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b text-left text-[10px] font-bold uppercase tracking-[.1em] text-slate-500">
                                    <th class="py-3 pr-4">Tujuan</th>
                                    <th class="py-3 pr-4">Tanggal antar</th>
                                    <th class="py-3 pr-4">Target</th>
                                    <th class="py-3 pr-4">Diambil</th>
                                    <th class="py-3 pr-4">Sisa setelah ambil</th>
                                    <th class="py-3 pr-4">Waktu</th>
                                    <th class="py-3 pr-4">Petugas</th>
                                    <th class="py-3 pr-4">Status</th>
                                    <th class="py-3 pr-4">Foto</th>
                                    <th class="py-3">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($selectedRun->items->sortBy('collected_at') as $item)
                                    @php
                                        $task = $item->task;
                                        $photoUrl = $item->photo_path
                                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($item->photo_path)
                                            : null;
                                    @endphp
                                    <tr class="border-b border-slate-100 align-top dark:border-slate-800">
                                        <td class="py-3 pr-4">
                                            <p class="font-bold">{{ $task?->destination_name ?: 'Tujuan tidak ditemukan' }}</p>
                                            @if($task?->address)
                                                <p class="mt-1 max-w-[240px] text-[11px] text-slate-500">{{ $task->address }}</p>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4">{{ $task?->delivery_date?->format('d/m/Y') ?: '—' }}</td>
                                        <td class="py-3 pr-4">{{ number_format((int) ($task?->target_containers ?? 0), 0, ',', '.') }}</td>
                                        <td class="py-3 pr-4 font-bold text-emerald-700 dark:text-emerald-300">
                                            {{ number_format((int) $item->collected_quantity, 0, ',', '.') }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            {{ number_format((int) ($item->remaining_after_collection ?? 0), 0, ',', '.') }}
                                        </td>
                                        <td class="py-3 pr-4">{{ $item->collected_at?->format('d/m/Y H:i') ?: '—' }}</td>
                                        <td class="py-3 pr-4">{{ $item->collector?->name ?: $selectedRun->driver_name_snapshot ?: '—' }}</td>
                                        <td class="py-3 pr-4">
                                            @if($item->status === 'collected')
                                                <span class="font-bold text-emerald-600">Selesai</span>
                                            @else
                                                <span class="font-bold text-amber-600">Sebagian</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4">
                                            @if($photoUrl)
                                                <button
                                                    type="button"
                                                    data-photo-url="{{ $photoUrl }}"
                                                    data-photo-title="{{ ($task?->destination_name ?: 'Dokumentasi Pengambilan Ompreng').' · '.($item->collected_at?->format('d/m/Y H:i') ?: '-') }}"
                                                    x-on:click="
                                                        photoModalUrl = $event.currentTarget.dataset.photoUrl;
                                                        photoModalTitle = $event.currentTarget.dataset.photoTitle;
                                                        photoModalOpen = true;
                                                    "
                                                    class="inline-flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-[11px] font-bold text-sky-700 transition hover:bg-sky-100 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-300 dark:hover:bg-sky-500/20"
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg"
                                                         viewBox="0 0 24 24"
                                                         fill="none"
                                                         stroke="currentColor"
                                                         stroke-width="1.8"
                                                         class="h-4 w-4">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6S2.25 12 2.25 12Z" />
                                                        <circle cx="12" cy="12" r="2.75" />
                                                    </svg>
                                                    Lihat foto
                                                </button>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="py-3">
                                            <span class="block max-w-[260px] whitespace-normal">{{ $item->notes ?: '—' }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="py-8 text-center text-slate-500">
                                            Belum ada tujuan yang dicatat pada kegiatan ini.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($selectedRun->washingSession)
                    <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
                        <p class="text-[10px] font-bold uppercase text-emerald-700 dark:text-emerald-300">Terhubung ke Pencucian</p>
                        <p class="mt-1 text-sm font-semibold text-emerald-900 dark:text-emerald-100">
                            Sesi Pencucian sudah dibuat dengan {{ number_format((int) $selectedRun->total_collected, 0, ',', '.') }} ompreng dari kegiatan ini.
                        </p>
                    </div>
                @endif
            </section>
        @endif

        {{-- ============================================================
            MODAL FOTO PENGAMBILAN OMPRENG
        ============================================================ --}}
        <div
            x-cloak
            x-show="photoModalOpen"
            x-transition.opacity
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            x-bind:aria-hidden="(! photoModalOpen).toString()"
        >
            <div
                class="absolute inset-0 bg-slate-950/75 backdrop-blur-sm"
                x-on:click="photoModalOpen = false"
            ></div>

            <div
                x-show="photoModalOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative z-10 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900"
                x-on:click.stop
            >
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5 dark:border-slate-700">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-sky-600 dark:text-sky-300">
                            Dokumentasi Pengambilan Ompreng
                        </p>
                        <h3
                            class="mt-1 truncate text-sm font-bold text-slate-900 sm:text-base dark:text-slate-100"
                            x-text="photoModalTitle || 'Foto pengambilan ompreng'"
                        ></h3>
                    </div>

                    <button
                        type="button"
                        x-on:click="photoModalOpen = false"
                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
                        aria-label="Tutup foto"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg"
                             viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="2"
                             class="h-5 w-5">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-slate-100 p-3 sm:p-5 dark:bg-slate-950">
                    <template x-if="photoModalUrl">
                        <img
                            x-bind:src="photoModalUrl"
                            x-bind:alt="photoModalTitle || 'Dokumentasi Pengambilan Ompreng'"
                            class="max-h-[72vh] max-w-full rounded-xl object-contain shadow-sm"
                        >
                    </template>
                </div>

                <div class="flex justify-end border-t border-slate-200 px-4 py-3 sm:px-5 dark:border-slate-700">
                    <button
                        type="button"
                        x-on:click="photoModalOpen = false"
                        class="h-10 rounded-xl bg-slate-900 px-5 text-xs font-bold text-white transition hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-v3.shell>
