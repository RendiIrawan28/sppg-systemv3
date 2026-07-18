<x-v3.shell :$unit :$navigation :$roleLabel :title="($record ? 'Rincian ' : 'Tambah ').$definition['label']" eyebrow="Dokumen operasional native V3">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center"><div><a wire:navigate href="{{ route('v3.operations.index', ['module' => $module]) }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke {{ $definition['label'] }}</a><h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $record?->{$definition['number']} ?: 'Dokumen baru' }}</h2><p class="mt-1 text-sm text-slate-500">{{ $definition['description'] }}</p></div>@if($record)<a href="{{ route($definition['pdf'], $record) }}" target="_blank" class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600">Unduh PDF</a>@endif</div>

        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @if ($record)
            @php($state = $record->state instanceof BackedEnum ? $record->state : null)
            @php($status = $record->status instanceof BackedEnum ? $record->status : null)
            <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl"><div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end"><div><p class="text-[10px] font-bold uppercase tracking-[.18em] text-cyan-300">Tahap operasional</p><h3 class="mt-2 text-2xl font-bold">{{ $state && method_exists($state, 'label') ? $state->label() : str($record->state)->headline() }}</h3><p class="mt-2 text-sm text-slate-300">Status laporan: {{ $status && method_exists($status, 'label') ? $status->label() : str($record->status)->headline() }}</p></div><div class="grid grid-cols-2 gap-3"><div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] text-slate-400">Dibuat</p><p class="mt-1 text-xs font-bold">{{ $record->created_at?->translatedFormat('d M Y H:i') }}</p></div><div class="rounded-xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] text-slate-400">Diperbarui</p><p class="mt-1 text-xs font-bold">{{ $record->updated_at?->diffForHumans() }}</p></div></div></div></section>
        @endif

        <form wire:submit="save" class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="mb-4"><h3 class="font-bold text-slate-900">Identitas dan ringkasan</h3><p class="mt-1 text-xs text-slate-400">Data utama dokumen operasional.</p></div><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($definition['fields'] as $field)
                    @php($model = 'data.'.$field['name'])
                    @php($optionKey = is_array($field['options']) ? md5(serialize($field['options'])) : (string) $field['options'])
                    <label class="{{ $field['type'] === 'textarea' ? 'sm:col-span-2 xl:col-span-4' : '' }}"><span class="mb-1.5 block text-xs font-semibold text-slate-600">{{ $field['label'] }} @if($field['required'])<b class="text-rose-500">*</b>@endif</span>
                        @if ($field['type'] === 'select')<select wire:model="{{ $model }}" @disabled(!$editable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60"><option value="">Pilih...</option>@foreach($fieldOptions[$optionKey] ?? [] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                        @elseif ($field['type'] === 'textarea')<textarea wire:model="{{ $model }}" rows="3" @disabled(!$editable) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60"></textarea>
                        @elseif ($field['type'] === 'boolean')<input wire:model="{{ $model }}" type="checkbox" @disabled(!$editable) class="size-5 rounded border-slate-300 text-sky-600">
                        @else<input wire:model="{{ $model }}" type="{{ $field['type'] === 'datetime' ? 'datetime-local' : $field['type'] }}" @if($field['type']==='number') step="any" @endif @disabled(!$editable) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60">@endif
                        @error($model)<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div></section>

            @foreach ($definition['relations'] as $relationName => $relationDefinition)
                <section wire:key="section-{{ $relationName }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between gap-3"><div><h3 class="font-bold text-slate-900">{{ $relationDefinition['label'] }}</h3><p class="mt-1 text-xs text-slate-400">{{ count($relations[$relationName] ?? []) }} catatan</p></div>@if($editable && $canUpdate)<button type="button" wire:click="addRelationRow('{{ $relationName }}')" class="h-9 rounded-xl bg-sky-50 px-3 text-xs font-bold text-sky-700">+ Tambah</button>@endif</div>
                    <div class="mt-4 space-y-3">
                        @forelse ($relations[$relationName] ?? [] as $index => $row)
                            <details wire:key="{{ $relationName }}-{{ $row['_id'] ?? 'new-'.$index }}" @if($loop->first) open @endif class="group rounded-xl border border-slate-200 bg-slate-50/60"><summary class="flex cursor-pointer list-none items-center justify-between p-4"><p class="text-sm font-bold text-slate-700">{{ $relationDefinition['label'] }} #{{ $index + 1 }}</p><x-v3.icon name="chevron-down" class="size-4 text-slate-400 transition group-open:rotate-180" /></summary><div class="border-t border-slate-200 p-4"><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($relationDefinition['fields'] as $field)
                                    @php($model = 'relations.'.$relationName.'.'.$index.'.'.$field['name'])
                                    @php($uploadModel = 'uploads.'.$relationName.'.'.$index.'.'.$field['name'])
                                    @php($optionKey = is_array($field['options']) ? md5(serialize($field['options'])) : (string) $field['options'])
                                    <label class="{{ $field['type'] === 'textarea' ? 'sm:col-span-2 xl:col-span-4' : '' }}"><span class="mb-1 block text-[11px] font-semibold text-slate-500">{{ $field['label'] }} @if($field['required'])<b class="text-rose-500">*</b>@endif</span>
                                        @if ($field['type'] === 'select')<select wire:model="{{ $model }}" @disabled(!$editable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih...</option>@foreach($fieldOptions[$optionKey] ?? [] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                                        @elseif ($field['type'] === 'textarea')<textarea wire:model="{{ $model }}" rows="2" @disabled(!$editable) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></textarea>
                                        @elseif ($field['type'] === 'boolean')<input wire:model="{{ $model }}" type="checkbox" @disabled(!$editable) class="size-5 rounded border-slate-300 text-sky-600">
                                        @elseif ($field['type'] === 'file')<input wire:model="{{ $uploadModel }}" type="file" accept="image/*" @disabled(!$editable) class="block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-2 file:font-bold file:text-sky-700">@if(!empty($row[$field['name']]))<a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($row[$field['name']]) }}" target="_blank" class="mt-1 inline-flex text-[11px] font-bold text-sky-700">Lihat file saat ini</a>@endif
                                        @else<input wire:model="{{ $model }}" type="{{ $field['type'] === 'datetime' ? 'datetime-local' : $field['type'] }}" @if($field['type']==='number') step="any" @endif @disabled(!$editable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@endif
                                        @error($model)<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror @error($uploadModel)<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>@if($editable && $canUpdate)<div class="mt-4 flex justify-end"><button type="button" wire:click="removeRelationRow('{{ $relationName }}', {{ $index }})" wire:confirm="Hapus baris ini?" class="text-xs font-bold text-rose-600">Hapus baris</button></div>@endif</div></details>
                        @empty <div class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-xs text-slate-400">Belum ada catatan.</div>
                        @endforelse
                    </div>
                </section>
            @endforeach

            @if($editable && ($canUpdate || !$record))<div class="sticky bottom-4 z-10 flex flex-wrap justify-end gap-2 rounded-2xl border border-slate-200 bg-white/90 p-3 shadow-xl backdrop-blur">@if($record && auth()->user()->can($definition['permission'].'.delete'))<button type="button" wire:click="delete" wire:confirm="Hapus dokumen ini?" class="h-10 rounded-xl px-4 text-xs font-bold text-rose-700">Hapus</button>@endif<button type="submit" class="h-10 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white">Simpan seluruh data</button></div>@endif
        </form>

        @if ($record)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-col justify-between gap-2 sm:flex-row"><div><h3 class="font-bold text-slate-900">Aksi alur kerja</h3><p class="mt-1 text-xs text-slate-400">Transisi memeriksa kelengkapan sesuai SOP setiap divisi.</p></div>@if($editable && $canUpdate)<button wire:click="checkReadiness" class="h-9 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600">Cek kesiapan</button>@endif</div>
                @if(in_array('handover', array_keys($actions), true))<div class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2 xl:grid-cols-4">@foreach($handover as $key => $value)<label><span class="mb-1 block text-[11px] font-semibold text-slate-500">{{ str($key)->headline() }}</span><input wire:model="handover.{{ $key }}" type="{{ str_contains($key, 'quantity') || str_contains($key, 'portions') ? 'number' : 'text' }}" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>@endforeach<label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Foto serah-terima</span><input wire:model="handoverPhoto" type="file" accept="image/*" class="block w-full text-xs"></label></div>@endif
                <label class="mt-4 block"><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan aksi / revisi</span><textarea wire:model="workflowNotes" rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Wajib untuk permintaan revisi"></textarea></label>
                <div class="mt-4 flex flex-wrap justify-end gap-2">@foreach($actions as $action => $label) @php($authorized = in_array($action, ['verify','revision'], true) ? $canApprove : $canUpdate) @if($authorized)<button wire:click="workflow('{{ $action }}')" wire:confirm="Lanjutkan aksi {{ $label }}?" class="h-10 rounded-xl px-4 text-xs font-bold text-white {{ $action === 'revision' ? 'bg-rose-600' : ($action === 'verify' || $action === 'submit' ? 'bg-emerald-600' : 'bg-slate-800') }}">{{ $label }}</button>@endif @endforeach</div>
            </section>
        @endif
    </div>
</x-v3.shell>
