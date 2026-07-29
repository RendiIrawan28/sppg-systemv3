<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Pengadaan" eyebrow="Kontrol peran dan harga">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.procurement.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 dark:text-sky-300">
                    <x-v3.icon name="arrow-left" class="size-4" /> Kembali ke pengadaan
                </a>
                <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{{ $request->request_number }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Dibutuhkan {{ $request->needed_date?->translatedFormat('d M Y') ?? 'tanpa tanggal' }} · {{ $request->total_items }} bahan
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($request->nutritionRequirementPlan)
                    <a wire:navigate href="{{ route('v3.nutrition.requirements.show', $request->nutritionRequirementPlan) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                        Lihat kebutuhan asal
                    </a>
                @endif

                @if ((auth()->user()->is_super_admin || auth()->user()->can('procurement.export')) && in_array($request->status, [\App\Models\ProcurementRequest::STATUS_APPROVED, \App\Models\ProcurementRequest::STATUS_ORDERED], true))
                    <a href="{{ route('procurement-requests.pdf', $request) }}" target="_blank" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Ekspor PDF</a>
                    <a href="{{ route('procurement-requests.excel', $request) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">Ekspor Excel</a>
                @endif

                @if ($request->status === \App\Models\ProcurementRequest::STATUS_ORDERED && (auth()->user()->is_super_admin || auth()->user()->can('stock.create')))
                    <button wire:click="createReceipt" wire:confirm="Buat atau buka dokumen penerimaan bahan?" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Buat penerimaan</button>
                @endif
            </div>
        </div>

        @if ($actionMessage)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">{{ $actionMessage }}</div>
        @endif
        @error('action')
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">{{ $message }}</div>
        @enderror

        <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold text-cyan-200 ring-1 ring-cyan-300/20">{{ $statuses[$request->status] ?? '-' }}</span>
                    <h3 class="mt-4 text-2xl font-bold">Rp {{ number_format((float) $request->estimated_total_amount, 0, ',', '.') }}</h3>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">{{ $request->notes ?: 'Tanpa catatan permintaan.' }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                        <p class="text-[10px] text-slate-400">Status harga</p>
                        <p class="mt-1 text-sm font-bold">{{ str($request->price_status)->replace('_', ' ')->title() }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                        <p class="text-[10px] text-slate-400">Bahan</p>
                        <p class="mt-1 text-xl font-bold">{{ $request->total_items }}</p>
                    </div>
                    <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                        <p class="text-[10px] text-slate-400">Pengaju</p>
                        <p class="mt-1 text-sm font-bold">{{ $request->submitter?->name ?? $request->creator?->name ?? '—' }}</p>
                    </div>
                    <div class="rounded-xl border border-cyan-300/20 bg-cyan-300/10 p-3">
                        <p class="text-[10px] text-cyan-200/70">Supplier terisi</p>
                        <p class="mt-1 text-xl font-bold text-cyan-100">{{ $request->items->whereNotNull('supplier_id')->count() }}/{{ $request->items->count() }}</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="flex flex-col gap-5">
            <aside class="contents">
                <section class="order-1 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700 dark:text-sky-300">Informasi permintaan</p>
                    <div class="mt-4 space-y-4">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Tanggal permintaan</span>
                            <input wire:model="requestDate" type="date" @disabled(! $canHeaderEdit) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Tanggal dibutuhkan</span>
                            <input wire:model="neededDate" type="date" @disabled(! $canHeaderEdit) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Catatan pengadaan</span>
                            <textarea wire:model="notes" rows="3" @disabled(! $canHeaderEdit) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"></textarea>
                        </label>
                        @if ($canHeaderEdit || $canItemEdit || $canSupplierEdit || $canPriceEdit)
                            <button wire:click="save" class="h-10 w-full rounded-xl border border-slate-200 text-xs font-bold text-slate-700 dark:border-slate-700 dark:text-slate-200">Simpan perubahan</button>
                        @endif
                    </div>
                </section>

                <section class="order-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700 dark:text-sky-300">Workflow</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Pemisahan peran tetap diberlakukan. Pengaju tidak dapat menyetujui permintaan sendiri.</p>
                    <label class="mt-4 block">
                        <span class="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">Catatan keputusan/revisi</span>
                        <textarea wire:model="decisionNotes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" placeholder="Wajib untuk permintaan revisi"></textarea>
                    </label>
                    <div class="mt-3 grid gap-2">
                        @if ($request->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('procurement.submit')))
                            <button wire:click="submit" wire:confirm="Ajukan permintaan ini?" class="h-10 rounded-xl bg-[#081d3a] text-xs font-bold text-white">Ajukan permintaan</button>
                        @endif
                        @if ($request->status === \App\Models\ProcurementRequest::STATUS_SUBMITTED && (auth()->user()->is_super_admin || auth()->user()->can('procurement.approve')))
                            <button wire:click="verifyFinance" wire:confirm="Verifikasi supplier, jumlah beli, satuan, dan harga seluruh bahan?" class="h-10 rounded-xl bg-sky-600 text-xs font-bold text-white">Verifikasi Keuangan</button>
                        @endif
                        @if ($request->status === \App\Models\ProcurementRequest::STATUS_FINANCE_VERIFIED && (auth()->user()->is_super_admin || auth()->user()->can('procurement.finalize_price')))
                            <button wire:click="finalizePrice" wire:confirm="Tetapkan dan kunci harga final?" class="h-10 rounded-xl bg-emerald-600 text-xs font-bold text-white">Tetapkan harga final</button>
                        @endif
                        @if (in_array($request->status, [\App\Models\ProcurementRequest::STATUS_SUBMITTED, \App\Models\ProcurementRequest::STATUS_FINANCE_VERIFIED], true) && (auth()->user()->is_super_admin || auth()->user()->can('procurement.approve') || auth()->user()->can('procurement.finalize_price')))
                            <button wire:click="requestRevision" class="h-10 rounded-xl bg-rose-50 text-xs font-bold text-rose-700 ring-1 ring-rose-100 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900">Minta revisi</button>
                        @endif
                        @if ($request->status === \App\Models\ProcurementRequest::STATUS_APPROVED && (auth()->user()->is_super_admin || auth()->user()->can('procurement.order')))
                            <button wire:click="markOrdered" wire:confirm="Tandai seluruh bahan sudah dipesan?" class="h-10 rounded-xl bg-violet-600 text-xs font-bold text-white">Tandai dipesan</button>
                        @endif
                        @if ($request->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('procurement.delete')))
                            <button wire:click="delete" wire:confirm="Hapus draft permintaan ini?" class="h-10 rounded-xl text-xs font-bold text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950/40">Hapus draft</button>
                        @endif
                    </div>
                    @if ($request->finance_notes)
                        <div class="mt-4 rounded-xl bg-amber-50 p-3 text-xs leading-5 text-amber-700 ring-1 ring-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
                            <strong>Catatan proses:</strong><br>{{ $request->finance_notes }}
                        </div>
                    @endif
                </section>
            </aside>

            <section class="order-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <div class="flex flex-col justify-between gap-4 border-b border-slate-100 p-5 dark:border-slate-800 lg:flex-row lg:items-end">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700 dark:text-sky-300">Item pembelian</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Pencatatan pembelian bahan</h3>
                        <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500 dark:text-slate-400">
                            Kebutuhan Ahli Gizi hanya ditampilkan sebagai referensi. Jumlah beli dan satuan beli diisi manual sesuai kemasan atau satuan supplier, tanpa konversi otomatis.
                        </p>
                    </div>
                    @if ($canItemEdit)
                        <div class="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                            <select wire:model="newIngredientId" class="h-10 min-w-64 rounded-xl border border-slate-200 bg-white px-3 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                <option value="">Pilih bahan tambahan</option>
                                @foreach ($availableIngredients as $ingredient)
                                    <option value="{{ $ingredient->id }}">{{ $ingredient->name }} · {{ $ingredient->measurementUnit?->symbol ?: $ingredient->measurementUnit?->code ?: 'unit' }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem" class="h-10 shrink-0 rounded-xl bg-sky-600 px-4 text-xs font-bold text-white disabled:opacity-60">+ Tambah item</button>
                        </div>
                    @endif
                </div>

                @error('newIngredientId')
                    <div class="border-b border-rose-100 bg-rose-50 px-5 py-2 text-xs font-semibold text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">{{ $message }}</div>
                @enderror

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1250px] text-left">
                        <thead>
                            <tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-500">
                                <th class="px-4 py-3.5">Bahan</th>
                                <th class="w-44 px-4 py-3.5">Kebutuhan referensi</th>
                                <th class="px-4 py-3.5">Supplier</th>
                                <th class="w-28 px-3 py-3.5 text-right">Jumlah beli</th>
                                <th class="w-40 px-3 py-3.5">Satuan beli</th>
                                <th class="w-32 px-3 py-3.5 text-right">Harga/satuan</th>
                                <th class="px-4 py-3.5 text-right">Subtotal</th>
                                <th class="px-4 py-3.5">Catatan</th>
                                @if ($canItemEdit)
                                    <th class="w-20 px-3 py-3.5 text-center">Aksi</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($request->items as $item)
                                @php
                                    $referenceQuantity = (float) ($item->requirement_quantity_snapshot ?? 0);
                                    $referenceUnit = $item->requirement_unit_snapshot ?: null;
                                @endphp
                                <tr wire:key="procurement-item-{{ $item->id }}" class="align-top">
                                    <td class="px-4 py-4">
                                        <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $item->ingredient_name_snapshot }}</p>
                                        <p class="mt-1 text-xs text-slate-400">{{ $item->ingredient_code_snapshot ?: 'Tanpa kode' }}</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        @if ($referenceQuantity > 0 && $referenceUnit)
                                            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ number_format($referenceQuantity, 4, ',', '.') }} {{ $referenceUnit }}</p>
                                            <p class="mt-1 text-[10px] text-slate-400">Referensi Ahli Gizi</p>
                                        @else
                                            <p class="text-xs font-semibold text-slate-400">Bahan tambahan</p>
                                            <p class="mt-1 text-[10px] text-slate-400">Tanpa kebutuhan asal</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4">
                                        <select wire:model="rows.{{ $item->id }}.supplier_id" @disabled(! $canSupplierEdit) class="h-10 w-52 rounded-xl border border-slate-200 bg-white px-3 text-xs disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                            <option value="">Pilih supplier</option>
                                            @foreach ($suppliers as $supplier)
                                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-4">
                                        <input wire:model="rows.{{ $item->id }}.requested_quantity" type="number" min="0.0001" step="0.0001" @disabled(! $canItemEdit) class="h-10 w-24 rounded-xl border border-slate-200 bg-white px-2 text-right text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                        @error("rows.{$item->id}.requested_quantity")
                                            <p class="mt-1 text-[10px] font-semibold text-rose-600">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-3 py-4">
                                        <select wire:change="changeUnit({{ $item->id }}, $event.target.value)" @disabled(! $canItemEdit) class="h-10 w-36 rounded-xl border border-slate-200 bg-white px-3 text-xs text-slate-700 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                            @foreach ($measurementUnits as $measurementUnit)
                                                <option value="{{ $measurementUnit->id }}" @selected((int) ($rows[$item->id]['measurement_unit_id'] ?? 0) === (int) $measurementUnit->id)>
                                                    {{ $measurementUnit->symbol ?: $measurementUnit->code }} · {{ $measurementUnit->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error("rows.{$item->id}.measurement_unit_id")
                                            <p class="mt-1 max-w-36 text-[10px] font-semibold leading-4 text-rose-600">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-3 py-4">
                                        <input wire:model="rows.{{ $item->id }}.estimated_unit_price" type="number" min="0" step="1" @disabled(! $canPriceEdit) class="h-10 w-28 rounded-xl border border-slate-200 bg-white px-2 text-right text-sm disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                        <p class="mt-1 text-right text-[10px] text-slate-400">per satuan beli</p>
                                    </td>
                                    <td class="px-4 py-4 text-right text-sm font-bold text-slate-700 dark:text-slate-200">
                                        Rp {{ number_format((float) (($rows[$item->id]['requested_quantity'] ?? 0) * ($rows[$item->id]['estimated_unit_price'] ?? 0)), 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <textarea wire:model="rows.{{ $item->id }}.notes" rows="2" @disabled(! $canItemEdit) class="w-48 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" placeholder="Tambahkan catatan jika ada"></textarea>
                                    </td>
                                    @if ($canItemEdit)
                                        <td class="px-3 py-4 text-center">
                                            <button type="button" wire:click="removeItem({{ $item->id }})" wire:confirm="Hapus {{ $item->ingredient_name_snapshot }} dari item pembelian?" wire:loading.attr="disabled" wire:target="removeItem({{ $item->id }})" class="h-9 rounded-lg px-3 text-xs font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-60 dark:text-rose-300 dark:hover:bg-rose-950/40">Hapus</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-v3.shell>
