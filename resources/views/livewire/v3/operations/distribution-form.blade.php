<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Distribusi" eyebrow="Pelaksanaan rute">
    @php
        $state = $record?->state;
        $status = $record?->status;
        $stateValue = $state instanceof BackedEnum ? $state->value : (string) $state;
        $statusValue = $status instanceof BackedEnum ? $status->value : (string) $status;
        $revisionMode = $editable
            && $canUpdate
            && $canOperateRoute
            && $stateValue === App\Enums\DistributionRunState::Returned->value
            && $statusValue === App\Enums\OperationalReportStatus::RevisionRequired->value;
        $assignmentEditable = $editable
            && $canUpdate
            && $canOperateRoute
            && $stateValue === App\Enums\DistributionRunState::Planned->value;
        $temperatureEditable = $editable
            && $canUpdate
            && $canOperateRoute
            && $stateValue === App\Enums\DistributionRunState::Loaded->value;
        $deliveryInProgress = $editable
            && $canUpdate
            && $canOperateRoute
            && $stateValue === App\Enums\DistributionRunState::Departed->value;
    @endphp

    <div class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100">
        <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.operations.index', ['module' => $module]) }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 transition hover:text-sky-800 dark:text-sky-300 dark:hover:text-sky-200">
                    <x-v3.icon name="arrow-left" class="size-4" /> Kembali ke Distribusi
                </a>
                <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                    {{ $record?->route_name ?: $record?->run_number }}
                </h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $record?->run_number }} · {{ $record?->distribution_date?->translatedFormat('d F Y') }}
                </p>
            </div>

            @if ($record)
                <a href="{{ route($definition['pdf'], $record) }}" target="_blank" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    Unduh PDF
                </a>
            @endif
        </div>

        @if ($actionMessage)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                {{ $actionMessage }}
            </div>
        @endif

        @error('action')
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-400/30 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</div>
        @enderror

        @if ($revisionMode)
            <div class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-300">
                Laporan dikembalikan untuk revisi. Perbaiki data pada tujuan, simpan koreksi, kemudian ajukan kembali laporan seluruh rute.
            </div>
        @endif

        <section class="rounded-[26px] border border-sky-300/10 bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-950/10 dark:border-sky-400/20 dark:bg-[#07182c] dark:shadow-none">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.18em] text-cyan-300">Status rute</p>
                    <h3 class="mt-2 text-2xl font-bold">{{ $state?->label() ?? str($stateValue)->headline() }}</h3>
                    <p class="mt-2 text-sm text-slate-300">Laporan: {{ $status?->label() ?? str($statusValue)->headline() }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3">
                        <p class="text-[10px] text-slate-400">Tujuan</p>
                        <p class="mt-1 text-lg font-bold">{{ $record?->stops()->count() ?? 0 }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3">
                        <p class="text-[10px] text-slate-400">Rencana porsi</p>
                        <p class="mt-1 text-lg font-bold">{{ number_format($record?->planned_total ?? 0, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3">
                        <p class="text-[10px] text-slate-400">Diserahkan</p>
                        <p class="mt-1 text-lg font-bold">{{ number_format($record?->delivered_total ?? 0, 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3">
                        <p class="text-[10px] text-slate-400">Tidak tersalurkan</p>
                        <p class="mt-1 text-lg font-bold">{{ number_format($record?->returned_total ?? 0, 0, ',', '.') }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="mb-4">
                <h3 class="font-bold text-slate-900 dark:text-slate-100">Rute dan penugasan</h3>
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Driver otomatis menggunakan akun yang memilih rute. Data rute berasal dari Asisten Lapangan.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Tanggal distribusi</span>
                    <input value="{{ $record?->distribution_date?->format('d-m-Y') }}" disabled class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Nama rute</span>
                    <input value="{{ $record?->route_name }}" disabled class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Menu</span>
                    <input value="{{ $record?->menu_name_snapshot }}" disabled class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Driver</span>
                    <input value="{{ $record?->driver_name ?: auth()->user()?->name }}" disabled class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                </label>

                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Nama kernet <b class="text-rose-500">*</b></span>
                    <input wire:model="data.kernet_name" type="text" @disabled(!$assignmentEditable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                    @error('data.kernet_name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Kendaraan <b class="text-rose-500">*</b></span>
                    <input wire:model="data.vehicle_name" type="text" @disabled(!$assignmentEditable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400" placeholder="Contoh: Mobil box">
                    @error('data.vehicle_name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Nomor polisi <b class="text-rose-500">*</b></span>
                    <input wire:model="data.vehicle_plate" type="text" @disabled(!$assignmentEditable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm uppercase text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400" placeholder="AB 1234 XX">
                    @error('data.vehicle_plate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                </label>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Suhu saat berangkat °C</span>
                    <input wire:model="data.departure_temperature_celsius" type="number" step="0.1" @disabled(!$temperatureEditable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                </label>
            </div>

            @if ($assignmentEditable && array_key_exists('claim', $actions))
                <div class="mt-4 flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-sky-400/30 dark:bg-sky-500/10">
                    <div>
                        <p class="text-sm font-bold text-sky-900 dark:text-sky-100">Pilih rute ini sebagai driver</p>
                        <p class="mt-1 text-xs text-sky-700 dark:text-sky-300">Isi nama kernet, kendaraan, dan nomor polisi di atas, kemudian pilih rute.</p>
                        @if ($errors->has('data.kernet_name') || $errors->has('data.vehicle_name') || $errors->has('data.vehicle_plate') || $errors->has('action'))
                            <p class="mt-2 text-xs font-bold text-rose-600">{{ $errors->first('action') ?: $errors->first('data.kernet_name') ?: $errors->first('data.vehicle_name') ?: $errors->first('data.vehicle_plate') }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="claimRoute"
                        wire:loading.attr="disabled"
                        wire:target="claimRoute"
                        class="h-11 shrink-0 rounded-xl bg-sky-700 px-5 text-xs font-bold text-white transition hover:bg-sky-800 disabled:cursor-wait disabled:opacity-60 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400"
                    >
                        <span wire:loading.remove wire:target="claimRoute">Pilih rute ini</span>
                        <span wire:loading wire:target="claimRoute">Memproses...</span>
                    </button>
                </div>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-bold text-slate-900 dark:text-slate-100">Tujuan pengantaran</h3>
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Urutan, jadwal, tujuan, dan porsi dikunci dari rencana Asisten Lapangan.</p>
                </div>
                <span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ count($relations['stops'] ?? []) }} tujuan</span>
            </div>

            <div class="mt-4 space-y-3">
                @foreach ($relations['stops'] ?? [] as $index => $row)
                    @php
                        $rowStatus = (string) ($row['status'] ?? App\Enums\DistributionStopStatus::Planned->value);
                        $stopStatus = App\Enums\DistributionStopStatus::tryFrom($rowStatus);
                        $terminalStop = in_array($rowStatus, [
                            App\Enums\DistributionStopStatus::Delivered->value,
                            App\Enums\DistributionStopStatus::Partial->value,
                            App\Enums\DistributionStopStatus::Failed->value,
                        ], true);
                        $stopEditable = ($deliveryInProgress && !$terminalStop) || ($revisionMode && $terminalStop);
                    @endphp

                    <details wire:key="distribution-stop-{{ $row['_id'] }}" @if($loop->first || $stopEditable) open @endif class="group overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/50 transition dark:border-slate-700 dark:bg-slate-800/60">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 transition hover:bg-slate-100/70 dark:hover:bg-slate-800">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white dark:bg-sky-500 dark:text-slate-950">{{ $row['sequence_order'] ?? $loop->iteration }}</div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ $row['destination_name'] }}</p>
                                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">
                                        Jadwal {{ filled($row['planned_arrival_at'] ?? null) ? \Illuminate\Support\Carbon::parse($row['planned_arrival_at'])->format('H:i') : '—' }} ·
                                        {{ number_format((int) ($row['small_portions'] ?? 0) + (int) ($row['large_portions'] ?? 0), 0, ',', '.') }} porsi
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $terminalStop ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' }}">
                                    {{ $stopStatus?->label() ?? 'Menunggu' }}
                                </span>
                                <x-v3.icon name="chevron-down" class="size-4 text-slate-400 transition group-open:rotate-180 dark:text-slate-500" />
                            </div>
                        </summary>

                        <div class="border-t border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            @if (filled($row['notes'] ?? null))
                                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-400/30 dark:bg-amber-500/10">
                                    <p class="text-[10px] font-bold uppercase tracking-[.12em] text-amber-700 dark:text-amber-300">Catatan Asisten Lapangan</p>
                                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">{{ $row['notes'] }}</p>
                                </div>
                            @endif

                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <label class="sm:col-span-2">
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Alamat</span>
                                    <textarea rows="2" disabled class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $row['address'] }}</textarea>
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Kontak tujuan</span>
                                    <input value="{{ $row['contact_name'] }}" disabled class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Nomor telepon</span>
                                    <input value="{{ $row['contact_phone'] }}" disabled class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Rencana porsi kecil</span>
                                    <input value="{{ $row['small_portions'] }}" disabled class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Rencana porsi besar</span>
                                    <input value="{{ $row['large_portions'] }}" disabled class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                </label>

                                @if ($revisionMode)
                                    <label class="sm:col-span-2">
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Status hasil pengantaran</span>
                                        <select wire:model="relations.stops.{{ $index }}.status" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-sky-500/20">
                                            <option value="{{ App\Enums\DistributionStopStatus::Delivered->value }}">Selesai</option>
                                            <option value="{{ App\Enums\DistributionStopStatus::Partial->value }}">Terkirim sebagian</option>
                                            <option value="{{ App\Enums\DistributionStopStatus::Failed->value }}">Gagal dikirim</option>
                                        </select>
                                    </label>
                                @endif

                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Porsi kecil diserahkan</span>
                                    <input wire:model="relations.stops.{{ $index }}.delivered_small_portions" type="number" min="0" max="{{ $row['small_portions'] }}" @disabled(!$stopEditable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                                    @error('relations.stops.'.$index.'.delivered_small_portions')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Porsi besar diserahkan</span>
                                    <input wire:model="relations.stops.{{ $index }}.delivered_large_portions" type="number" min="0" max="{{ $row['large_portions'] }}" @disabled(!$stopEditable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                                    @error('relations.stops.'.$index.'.delivered_large_portions')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Ompreng/wadah yang diserahkan</span>
                                    <input wire:model="relations.stops.{{ $index }}.containers_sent" type="number" min="0" @disabled(!$stopEditable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                                    <span class="mt-1 block text-[10px] text-slate-400">Diisi sesuai jumlah wadah yang benar-benar ditinggalkan di instansi, bukan jumlah porsi.</span>
                                    @error('relations.stops.'.$index.'.containers_sent')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Nama penerima</span>
                                    <input wire:model="relations.stops.{{ $index }}.recipient_name" type="text" @disabled(!$stopEditable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                                    @error('relations.stops.'.$index.'.recipient_name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label>
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Jabatan penerima</span>
                                    <input wire:model="relations.stops.{{ $index }}.recipient_position" type="text" @disabled(!$stopEditable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                                </label>
                                <label class="sm:col-span-2">
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Dokumentasi serah-terima</span>
                                    @if (!empty($row['handover_photo_path']))
                                        <x-v3.documentation-button
                                            :url="\Illuminate\Support\Facades\Storage::disk('public')->url($row['handover_photo_path'])"
                                            :title="'Dokumentasi serah-terima · '.($row['destination_name'] ?: 'Tujuan distribusi')"
                                            label="Lihat foto tersimpan"
                                            class="mb-2"
                                        />
                                    @endif
                                    @if ($stopEditable)
                                        <input wire:model="uploads.stops.{{ $index }}.handover_photo_path" type="file" accept="image/*" class="block w-full rounded-xl border border-slate-200 bg-white text-xs text-slate-700 file:mr-3 file:border-0 file:bg-sky-600 file:px-4 file:py-2.5 file:text-xs file:font-bold file:text-white hover:file:bg-sky-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200 dark:file:bg-sky-500 dark:file:text-slate-950">
                                    @endif
                                    @error('uploads.stops.'.$index.'.handover_photo_path')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="sm:col-span-2">
                                    <span class="mb-1 block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Alasan pengiriman sebagian atau gagal</span>
                                    <textarea wire:model="relations.stops.{{ $index }}.failure_reason" rows="2" @disabled(!$stopEditable) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:bg-slate-50 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-400"></textarea>
                                    @error('relations.stops.'.$index.'.failure_reason')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                            </div>

                            @if ($deliveryInProgress && !$terminalStop)
                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="distributionStopWorkflow({{ $index }}, 'fail')" wire:confirm="Tandai tujuan ini gagal dikirim?" wire:loading.attr="disabled" wire:target="distributionStopWorkflow" class="h-10 rounded-xl bg-rose-600 px-4 text-xs font-bold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:text-slate-950 dark:hover:bg-rose-400">
                                        Gagal dikirim
                                    </button>
                                    <button type="button" wire:click="distributionStopWorkflow({{ $index }}, 'deliver')" wire:loading.attr="disabled" wire:target="distributionStopWorkflow" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">
                                        Simpan selesai / sebagian
                                    </button>
                                </div>
                            @elseif ($revisionMode && $terminalStop)
                                <div class="mt-4 flex justify-end">
                                    <button type="button" wire:click="saveStopRevision({{ $index }})" wire:loading.attr="disabled" wire:target="saveStopRevision" class="h-10 rounded-xl bg-amber-600 px-4 text-xs font-bold text-white transition hover:bg-amber-700 disabled:cursor-wait disabled:opacity-60 dark:bg-amber-500 dark:text-slate-950 dark:hover:bg-amber-400">
                                        Simpan koreksi tujuan
                                    </button>
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div>
                <h3 class="font-bold text-slate-900 dark:text-slate-100">Aksi rute dan laporan</h3>
                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Laporan seluruh rute baru dapat diajukan setelah semua driver kembali ke SPPG.</p>
            </div>

            @if ($actions !== [])
                <label class="mt-4 block">
                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Catatan aksi</span>
                    <textarea wire:model="workflowNotes" rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-sky-500/20" placeholder="Catatan opsional, wajib untuk permintaan revisi"></textarea>
                </label>
            @endif

            @if ($stateValue === App\Enums\DistributionRunState::Departed->value && $actions === [])
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-semibold text-amber-700 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-300">
                    Isi hasil setiap tujuan. Status rute berubah otomatis setelah seluruh tujuan selesai.
                </div>
            @endif

            @if ($stateValue === App\Enums\DistributionRunState::Returned->value && !$record->allRoutesReturned())
                <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs font-semibold text-sky-700 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-300">
                    Rute ini sudah kembali. Driver dapat memilih rute berikutnya, tetapi laporan belum dapat diajukan karena masih ada rute yang berjalan.
                </div>
            @endif

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                @foreach ($actions as $action => $label)
                    @continue($action === 'claim')
                    @php
                        $authorized = in_array($action, ['verify', 'revision'], true)
                            ? $canApprove
                            : ($action === 'submit' ? $canSubmit : $canUpdate);
                    @endphp
                    @if ($authorized)
                        <button
                            type="button"
                            wire:click="workflow('{{ $action }}')"
                            wire:confirm="Lanjutkan aksi {{ $label }}?"
                            wire:loading.attr="disabled"
                            wire:target="workflow"
                            class="h-10 rounded-xl px-4 text-xs font-bold text-white transition disabled:cursor-wait disabled:opacity-60 {{ in_array($action, ['release', 'revision'], true) ? 'bg-rose-600 hover:bg-rose-700 dark:bg-rose-500 dark:text-slate-950 dark:hover:bg-rose-400' : (in_array($action, ['submit', 'verify'], true) ? 'bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400' : 'bg-slate-800 hover:bg-slate-900 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400') }}"
                        >
                            {{ $label }}
                        </button>
                    @endif
                @endforeach
            </div>
        </section>
    </div>
</x-v3.shell>
