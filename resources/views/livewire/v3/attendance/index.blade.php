<x-v3.shell :$unit :$navigation :$roleLabel title="Presensi Pegawai" eyebrow="RFID masuk dan pulang">
    <div wire:poll.15s class="mx-auto max-w-[1450px] space-y-5">
        <section class="overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-8">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-cyan-300">Presensi RFID</p>
                    <h2 class="mt-2 text-2xl font-bold">Kehadiran pegawai SPPG</h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">Jadwal divisi menentukan keterlambatan dan ketidakhadiran. Minimal kerja 4 jam, jeda masuk kembali 6 jam, dan pulang otomatis setelah 14 jam.</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach(['present'=>'Hadir','late'=>'Terlambat','working'=>'Bekerja','permission'=>'Izin','sick'=>'Sakit','absent'=>'Tidak Berangkat'] as $key=>$label)
                        <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase text-slate-300">{{ $label }}</p><p class="mt-1 text-xl font-bold">{{ $summary[$key] }}</p></div>
                    @endforeach
                </div>
            </div>
        </section>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @if($revealedDeviceKey)
            <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950">
                <p class="font-bold">Salin kredensial perangkat sekarang</p>
                <p class="mt-1 text-xs">Kunci hanya ditampilkan sekali dan tidak dapat dilihat kembali.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2"><div class="rounded-xl bg-white p-3"><p class="text-[10px] font-bold uppercase text-amber-700">DEVICE_CODE</p><code class="mt-1 block break-all text-sm">{{ $revealedDeviceCode }}</code></div><div class="rounded-xl bg-white p-3"><p class="text-[10px] font-bold uppercase text-amber-700">DEVICE_KEY</p><code class="mt-1 block break-all text-sm">{{ $revealedDeviceKey }}</code></div></div>
            </section>
        @endif

        <nav class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
            @foreach(['attendance'=>'Kehadiran','correction'=>'Input & koreksi','registration'=>'Daftarkan kartu','devices'=>'Perangkat','taps'=>'Riwayat tap'] as $tab=>$label)
                @if($tab === 'attendance' || ($tab === 'correction' && $canCorrect) || ($tab === 'registration' && $canManage) || ($tab === 'devices' && $canDevices) || $tab === 'taps')
                    <button wire:click="$set('activeTab','{{ $tab }}')" class="h-10 shrink-0 rounded-xl px-4 text-sm font-bold {{ $activeTab === $tab ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ $label }}</button>
                @endif
            @endforeach
        </nav>

        @if($activeTab === 'attendance')
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="space-y-4 border-b border-slate-100 p-5 dark:border-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="font-bold text-slate-900 dark:text-white">Kehadiran per tanggal kerja</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-300">Shift lintas tengah malam mengikuti tanggal mulai jadwal. Terlambat merupakan bagian dari jumlah hadir.</p></div>@if($canSchedules)<a href="{{ route('v3.attendance.work-schedules') }}" wire:navigate class="rounded-xl bg-sky-50 px-4 py-3 text-sm font-bold text-sky-700 dark:bg-sky-950 dark:text-sky-200">Jam Kerja & Shift</a>@endif</div>
                    <div class="flex flex-wrap gap-2">
                        <input aria-label="Tanggal presensi" wire:model.live="filterDate" type="date" class="h-10 rounded-xl border border-slate-200 px-3 text-sm dark:bg-slate-800">
                        <select aria-label="Divisi" wire:model.live="filterDivisionId" class="h-10 rounded-xl border border-slate-200 px-3 text-sm dark:bg-slate-800"><option value="">Semua divisi</option>@foreach($divisions as $division)<option value="{{ $division->id }}">{{ $division->name }}</option>@endforeach</select>
                        <input aria-label="Cari pegawai" wire:model.live.debounce.350ms="search" type="search" class="h-10 rounded-xl border border-slate-200 px-3 text-sm dark:bg-slate-800" placeholder="Cari nama atau UID">
                        @if($canExport && $validDate)
                            <a href="{{ route('v3.attendance.pdf', ['date_from'=>$filterDate,'date_to'=>$filterDate,'division_id'=>$filterDivisionId ?: null,'search'=>$search]) }}" target="_blank" rel="noopener" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700 dark:text-slate-200">PDF</a>
                            <a href="{{ route('v3.attendance.xlsx', ['date_from'=>$filterDate,'date_to'=>$filterDate,'division_id'=>$filterDivisionId ?: null,'search'=>$search]) }}" class="inline-flex h-10 items-center justify-center rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Excel</a>
                        @endif
                        @if($canReset && $sessions->isNotEmpty())<button wire:click="openResetPanel" type="button" class="inline-flex h-10 items-center justify-center rounded-xl bg-rose-50 px-4 text-xs font-bold text-rose-700">Reset tanggal ini</button>@endif
                    </div>
                    @if(! $validDate)<p role="alert" class="text-sm text-rose-600">Pilih tanggal presensi yang valid.</p>@endif
                </div>
                @forelse($sessionGroups as $group)
                    <div class="border-b border-slate-200 dark:border-slate-700">
                        <h4 class="bg-slate-100 px-5 py-3 font-bold text-slate-800 dark:bg-slate-800 dark:text-white">{{ $group['name'] }} <span class="text-sm font-normal">· {{ $group['sessions']->pluck('user_id')->unique()->count() }} pegawai</span></h4>
                        <div class="overflow-x-auto"><table class="w-full min-w-[1200px] text-left text-sm"><thead><tr class="border-b border-slate-100 text-xs text-slate-500 dark:text-slate-300"><th class="p-3">Pegawai / UID</th><th class="p-3">Shift</th><th class="p-3">Jadwal masuk–pulang</th><th class="p-3">Masuk</th><th class="p-3">Pulang</th><th class="p-3">Durasi</th><th class="p-3">Status</th><th class="p-3">Keterangan</th><th class="p-3">Sumber</th><th class="p-3">Catatan</th>@if($canCorrect)<th class="p-3">Aksi</th>@endif</tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">@foreach($group['sessions'] as $session)<tr wire:key="attendance-{{ $session->id }}">
                            <td class="p-3"><p class="font-semibold">{{ $session->user?->name ?? 'Pegawai tidak tersedia' }}</p><p class="text-xs text-slate-500 dark:text-slate-300">UID {{ $session->user?->employee_number ?: 'belum terdaftar' }}</p></td>
                            <td class="p-3">{{ $session->shift_name_snapshot ?: 'Tidak terjadwal' }}</td>
                            <td class="p-3 whitespace-nowrap">{{ $session->scheduled_check_in_at?->format('H:i') ?: '—' }} – {{ $session->scheduled_check_out_at?->format('H:i') ?: '—' }}@if($session->scheduled_check_out_at && $session->scheduled_check_in_at && ! $session->scheduled_check_out_at->isSameDay($session->scheduled_check_in_at))<span class="block text-xs">(+1 hari)</span>@endif</td>
                            <td class="p-3">{{ $session->check_in_at?->format('d/m H:i') ?: '—' }}</td>
                            <td class="p-3">{{ $session->check_out_at?->format('d/m H:i') ?: ($session->status === 'present' && $session->check_in_at ? 'Masih bekerja' : '—') }}@if($session->check_out_source === 'automatic')<span class="block text-xs text-amber-600">Otomatis 14 jam</span>@endif</td>
                            <td class="p-3 whitespace-nowrap">@if($session->durationMinutes() !== null){{ intdiv($session->durationMinutes(),60) }}j {{ $session->durationMinutes()%60 }}m @else—@endif</td>
                            <td class="p-3">{{ $session->statusLabel() }}</td><td class="p-3 {{ $session->punctuality_status === 'late' ? 'text-amber-700 dark:text-amber-300' : '' }}">{{ $session->attendanceRemark() }}</td><td class="p-3">{{ $session->sourceLabel() }}</td><td class="max-w-[220px] p-3 text-xs">{{ $session->notes ?: '—' }}</td>
                            @if($canCorrect)<td class="p-3"><button wire:click="editSession({{ $session->id }})" class="rounded-lg bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 dark:bg-sky-950 dark:text-sky-200">Koreksi</button></td>@endif
                        </tr>@endforeach</tbody></table></div>
                    </div>
                @empty<p class="p-10 text-center text-sm text-slate-500 dark:text-slate-300">Belum ada data presensi sesuai tanggal dan filter ini.</p>@endforelse
            </section>

            @if($showResetPanel && $canReset)
                <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5 shadow-sm sm:p-6">
                    <div class="flex items-start gap-3">
                        <span class="mt-1 size-2 shrink-0 rounded-full bg-rose-500"></span>
                        <div>
                            <h3 class="font-bold text-rose-950">Reset presensi tanggal {{ \Carbon\Carbon::parse($filterDate)->format('d/m/Y') }}</h3>
                            <p class="mt-1 text-sm text-rose-800">Seluruh data presensi pada tanggal ini akan disembunyikan dari laporan. Riwayat tap RFID tetap disimpan dan data reset tetap dapat diaudit.</p>
                        </div>
                    </div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label><span class="mb-1 block text-xs font-semibold text-rose-900">Alasan reset *</span><textarea wire:model="resetReason" rows="3" class="w-full rounded-xl border border-rose-200 bg-white px-3 py-2 text-sm" placeholder="Jelaskan alasan data perlu direset"></textarea>@error('resetReason')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                        <label><span class="mb-1 block text-xs font-semibold text-rose-900">Ketik RESET untuk konfirmasi *</span><input wire:model="resetConfirmation" type="text" autocomplete="off" class="h-11 w-full rounded-xl border border-rose-200 bg-white px-3 text-sm" placeholder="RESET">@error('resetConfirmation')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                    </div>
                    <div class="mt-5 flex flex-wrap justify-end gap-2"><button wire:click="$set('showResetPanel', false)" type="button" class="h-11 rounded-xl border border-rose-200 bg-white px-5 text-sm font-bold text-slate-700">Batal</button><button wire:click="resetAttendance" wire:loading.attr="disabled" type="button" class="h-11 rounded-xl bg-rose-600 px-5 text-sm font-bold text-white disabled:opacity-50">Reset data presensi</button></div>
                </section>
            @endif
        @endif

        @if($activeTab === 'correction' && $canCorrect)
            <form wire:submit="saveManual" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div><h3 class="text-lg font-bold text-slate-900">{{ $sessionId ? 'Koreksi presensi' : 'Tambah presensi manual' }}</h3><p class="mt-1 text-sm text-slate-500">Setiap perubahan disimpan dalam riwayat audit dan wajib memiliki alasan.</p></div>
                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Relawan *</span><select wire:model="manualUserId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih relawan</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} · {{ $user->employee_number ?: 'UID kosong' }}</option>@endforeach</select>@error('manualUserId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Tanggal kerja *</span><input wire:model="manualWorkDate" type="date" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">@error('manualWorkDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Status *</span><select wire:model.live="manualStatus" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="present">Hadir</option><option value="permission">Izin</option><option value="sick">Sakit</option><option value="absent">Tidak Berangkat</option></select></label>
                    @if($manualStatus === 'present')<label><span class="mb-1 block text-xs font-semibold text-slate-600">Jam masuk *</span><input wire:model="manualCheckIn" type="time" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">@error('manualCheckIn')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label><label><span class="mb-1 block text-xs font-semibold text-slate-600">Jam pulang</span><input wire:model="manualCheckOut" type="time" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm"><span class="mt-1 block text-[10px] text-slate-400">Jika lebih kecil dari jam masuk, dianggap hari berikutnya.</span></label>@endif
                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan</span><input wire:model="manualNotes" type="text" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Opsional"></label>
                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Alasan input/koreksi *</span><textarea wire:model="manualReason" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Contoh: pegawai lupa membawa kartu"></textarea>@error('manualReason')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                </div>
                <div class="mt-5 flex justify-end"><button type="submit" class="h-11 rounded-xl bg-sky-600 px-5 text-sm font-bold text-white">{{ $sessionId ? 'Simpan koreksi' : 'Tambah presensi' }}</button></div>
            </form>
        @endif

        @if($activeTab === 'registration' && $canManage)
            <div class="grid gap-5 lg:grid-cols-2">
                <form wire:submit="startRegistration" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h3 class="text-lg font-bold text-slate-900">Daftarkan kartu RFID</h3><p class="mt-1 text-sm text-slate-500">Pilih relawan dan alat, lalu tempelkan kartu dalam dua menit.</p><div class="mt-5 space-y-4"><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Relawan *</span><select wire:model="registrationUserId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih relawan</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} · {{ $user->employee_number ?: 'belum ada UID' }}</option>@endforeach</select>@error('registrationUserId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Perangkat *</span><select wire:model="registrationDeviceId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih perangkat</option>@foreach($devices->where('is_active',true) as $device)<option value="{{ $device->id }}">{{ $device->name }} · {{ $device->location ?: $device->code }}</option>@endforeach</select>@error('registrationDeviceId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label></div><div class="mt-5 flex justify-end"><button class="h-11 rounded-xl bg-sky-600 px-5 text-sm font-bold text-white">Aktifkan pendaftaran</button></div></form>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><h3 class="text-lg font-bold text-slate-900">Status pendaftaran</h3>@if($activeRegistration)<div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5"><p class="text-xs font-bold uppercase text-amber-700">Menunggu kartu ditempel</p><p class="mt-2 text-lg font-bold text-amber-950">{{ $activeRegistration->user->name }}</p><p class="mt-1 text-sm text-amber-800">Perangkat: {{ $activeRegistration->device->name }} · berakhir {{ $activeRegistration->expires_at->format('H:i:s') }}</p><button wire:click="cancelRegistration({{ $activeRegistration->id }})" class="mt-4 text-xs font-bold text-rose-600">Batalkan pendaftaran</button></div>@else<p class="mt-5 rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Tidak ada pendaftaran kartu yang aktif.</p>@endif</section>
            </div>
        @endif

        @if($activeTab === 'devices' && $canDevices)
            <div class="grid gap-5 xl:grid-cols-[420px_1fr]">
                <form wire:submit="createDevice" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Tambah perangkat RFID</h3><div class="mt-4 space-y-3"><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Nama perangkat *</span><input wire:model="deviceName" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Contoh: Presensi pintu utama">@error('deviceName')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Kode perangkat *</span><input wire:model="deviceCode" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm uppercase" placeholder="RFID-UTAMA">@error('deviceCode')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label><label class="block"><span class="mb-1 block text-xs font-semibold text-slate-600">Lokasi</span><input wire:model="deviceLocation" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Pintu masuk"></label></div><button class="mt-5 h-11 w-full rounded-xl bg-sky-600 text-sm font-bold text-white">Buat perangkat</button></form>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Daftar perangkat</h3><div class="mt-4 space-y-3">@forelse($devices as $device)<article class="rounded-xl border border-slate-200 p-4"><div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start"><div><div class="flex items-center gap-2"><p class="font-bold text-slate-800">{{ $device->name }}</p><span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $device->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $device->is_active ? 'Aktif' : 'Nonaktif' }}</span></div><p class="mt-1 text-xs text-slate-500">{{ $device->code }} · {{ $device->location ?: 'lokasi belum diisi' }}</p><p class="mt-1 text-xs text-slate-400">{{ $device->last_seen_at ? 'Terhubung '.$device->last_seen_at->diffForHumans() : 'Belum pernah terhubung' }}@if($device->firmware_version) · firmware {{ $device->firmware_version }}@endif</p></div><div class="flex gap-2"><button wire:click="rotateDeviceKey({{ $device->id }})" wire:confirm="Ganti kunci? Firmware dengan kunci lama akan langsung ditolak." class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">Ganti kunci</button><button wire:click="toggleDevice({{ $device->id }})" class="rounded-lg px-3 py-2 text-xs font-bold {{ $device->is_active ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $device->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></div></div></article>@empty<p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Belum ada perangkat presensi.</p>@endforelse</div></section>
            </div>
        @endif

        @if($activeTab === 'taps')
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">20 tap terakhir</h3><div class="mt-4 space-y-2">@forelse($recentTaps as $tap)<article class="flex flex-col justify-between gap-2 rounded-xl border border-slate-200 px-4 py-3 sm:flex-row sm:items-center"><div><p class="text-sm font-bold text-slate-800">{{ $tap->user?->name ?: 'Kartu tidak dikenal' }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $tap->uid_snapshot }} · {{ $tap->device->name }} · {{ $tap->tapped_at->translatedFormat('d M Y H:i:s') }}</p></div><div class="flex items-center gap-2"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $tap->result === 'success' ? 'bg-emerald-50 text-emerald-700' : ($tap->result === 'ignored' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700') }}">{{ ['check_in'=>'Masuk','check_out'=>'Pulang','duplicate_tap'=>'Tap ganda','wait_4_hours'=>'Belum 4 jam kerja','wait_6_hours'=>'Menunggu 6 jam','uid_not_found'=>'UID tidak dikenal','register_card'=>'Kartu didaftarkan','already_registered'=>'Kartu sudah dipakai'][$tap->action] ?? $tap->action }}</span>@if($tap->is_offline)<span class="rounded-full bg-violet-50 px-2.5 py-1 text-[10px] font-bold text-violet-700">Sinkron offline</span>@endif</div></article>@empty<p class="p-8 text-center text-sm text-slate-400">Belum ada kartu yang dibaca.</p>@endforelse</div></section>
        @endif
    </div>
</x-v3.shell>
