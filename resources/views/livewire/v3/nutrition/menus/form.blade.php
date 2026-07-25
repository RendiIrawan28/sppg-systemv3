<x-v3.shell :$unit :$navigation :$roleLabel title="Editor Resep & Gramasi" eyebrow="Seperti lembar resep Ahli Gizi">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.nutrition.menu-matrix') }}" class="text-xs font-bold text-sky-700">← Kembali ke matriks</a>
                <h2 class="mt-3 text-2xl font-bold text-slate-950">{{ $menu->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $menu->code }} · {{ $menu->status->label() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="toggleSpecialGramasi" class="h-10 rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600">{{ $showSpecialGramasi ? 'Sembunyikan gramasi khusus' : 'Atur gramasi khusus' }}</button>
                @if($editable)
                    <button type="button" wire:click="recalculate" wire:loading.attr="disabled" class="h-10 rounded-xl bg-sky-50 px-4 text-xs font-bold text-sky-700 disabled:opacity-60">
                        <span wire:loading.remove wire:target="recalculate">Simpan & lihat nilai gizi</span>
                        <span wire:loading wire:target="recalculate">Menghitung...</span>
                    </button>
                @else
                    <a wire:navigate href="{{ route('v3.nutrition.menus.nutrition', $menu) }}" class="inline-flex h-10 items-center rounded-xl bg-sky-50 px-4 text-xs font-bold text-sky-700">Lihat nilai gizi</a>
                @endif
            </div>
        </div>

        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <form wire:submit="save" class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-900">Identitas menu</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Kode</span><input wire:model="code" @disabled(!$editable) class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm"></label>
                    <label class="xl:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Nama menu</span><input wire:model="name" @disabled(!$editable) class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm"></label>
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Porsi rencana</span><input wire:model="plannedPortions" type="number" min="0" @disabled(!$editable) class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm"></label>
                    <label class="sm:col-span-2 xl:col-span-4"><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan</span><textarea wire:model="notes" rows="2" @disabled(!$editable) class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"></textarea></label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between"><div><h3 class="font-bold text-slate-900">Kelompok penerima</h3><p class="mt-1 text-xs text-slate-400">Dipakai untuk membandingkan hasil gizi dengan standar kelompok.</p></div>@if($editable)<button type="button" wire:click="addCategory" class="text-xs font-bold text-sky-700">+ Kategori</button>@endif</div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($categories as $index => $row)
                        <div class="flex gap-2"><select wire:model="categories.{{ $index }}.beneficiary_category_id" @disabled(!$editable) class="h-10 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih kategori</option>@foreach($categoryOptions as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select><input type="hidden" wire:model="categories.{{ $index }}.portion_multiplier">@if($editable)<button type="button" wire:click="removeCategory({{ $index }})" class="px-2 text-xs font-bold text-rose-600">Hapus</button>@endif</div>
                    @endforeach
                </div>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between"><div><h3 class="font-bold text-slate-900">Resep dan gramasi</h3><p class="mt-1 text-xs text-slate-400">Gramasi utama diambil otomatis dari Standar Gizi siswa. BDD, gizi, harga, susut, dan pembulatan mengikuti Master Bahan.</p></div>@if($editable)<button type="button" wire:click="addItem" class="h-9 rounded-xl bg-sky-600 px-3 text-xs font-bold text-white">+ Hidangan</button>@endif</div>

                @foreach ($items as $itemIndex => $item)
                    <details open class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <summary class="cursor-pointer list-none p-5"><h4 class="font-bold text-slate-900">{{ $item['name'] ?: 'Hidangan baru' }}</h4><p class="mt-1 text-xs text-slate-400">{{ $componentOptions[$item['item_type']] ?? 'Komponen' }} · {{ count($item['ingredients']) }} bahan</p></summary>
                        <div class="border-t border-slate-100 p-5">
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Nama hidangan</span><input wire:model="items.{{ $itemIndex }}.name" @disabled(!$editable) class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"></label>
                                <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Komponen</span><select wire:model="items.{{ $itemIndex }}.item_type" @disabled(!$editable) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@foreach($componentOptions as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                                <div class="rounded-xl border border-sky-100 bg-sky-50 px-3 py-2"><span class="block text-[10px] font-semibold text-sky-700">Total berat kecil</span><strong class="text-sm text-slate-900">{{ number_format((float) $item['portion_weight_small_grams'], 2, ',', '.') }} g</strong></div>
                                <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2"><span class="block text-[10px] font-semibold text-indigo-700">Total berat besar</span><strong class="text-sm text-slate-900">{{ number_format((float) $item['portion_weight_large_grams'], 2, ',', '.') }} g</strong></div>
                                <input type="hidden" wire:model="items.{{ $itemIndex }}.menu_audience"><input type="hidden" wire:model="items.{{ $itemIndex }}.sort_order">
                                @if ($showSpecialGramasi)
                                    <label><span class="mb-1 block text-[11px] font-semibold text-amber-700">Berat khusus Balita (g)</span><input wire:model="items.{{ $itemIndex }}.portion_weight_toddler_grams" type="number" step=".001" min="0" @disabled(!$editable) class="h-10 w-full rounded-xl border border-amber-200 bg-amber-50 px-3 text-sm"></label>
                                    <label><span class="mb-1 block text-[11px] font-semibold text-amber-700">Berat khusus Bumil/Busui (g)</span><input wire:model="items.{{ $itemIndex }}.portion_weight_maternal_grams" type="number" step=".001" min="0" @disabled(!$editable) class="h-10 w-full rounded-xl border border-amber-200 bg-amber-50 px-3 text-sm"></label>
                                @endif
                                <label class="sm:col-span-2 xl:col-span-4"><span class="mb-1 block text-[11px] font-semibold text-slate-500">Catatan pengolahan</span><textarea wire:model="items.{{ $itemIndex }}.preparation_notes" rows="2" @disabled(!$editable) class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"></textarea></label>
                            </div>

                            <div class="mt-5 flex items-center justify-between border-t border-slate-100 pt-4"><div><p class="text-xs font-bold uppercase tracking-wider text-sky-700">Bahan per porsi</p><p class="mt-1 text-[10px] text-slate-400">Pilih bahan; porsi kecil dan besar akan terisi seperti VLOOKUP pada spreadsheet.</p></div>@if($editable)<button type="button" wire:click="addIngredient({{ $itemIndex }})" class="text-xs font-bold text-sky-700">+ Tambah bahan</button>@endif</div>
                            <div class="mt-3 space-y-3">
                                @foreach ($item['ingredients'] as $ingredientIndex => $ingredient)
                                    <div class="grid gap-3 rounded-xl border {{ ($ingredient['portion_source'] ?? 'manual') === 'standard' ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40' }} p-4 sm:grid-cols-2 xl:grid-cols-8">
                                        <label class="xl:col-span-2"><span class="mb-1 block text-[10px] font-semibold text-slate-500">Bahan / makanan</span><select wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.ingredient_id" wire:change="applyIngredientStandard({{ $itemIndex }}, {{ $ingredientIndex }})" @disabled(!$editable) class="h-9 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs"><option value="">Pilih bahan</option>@foreach($ingredientOptions as $id=>$option)<option value="{{ $id }}">{{ $option['name'] }}{{ $option['has_standard'] ? ' · standar tersedia' : '' }}</option>@endforeach</select></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-slate-500">Satuan resep</span><select wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.measurement_unit_id" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-slate-200 bg-white px-2 text-xs"><option value="">Pilih</option>@foreach($unitOptions as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-slate-500">Gram/satuan</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.grams_per_unit_snapshot" type="number" step=".0001" min=".0001" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-slate-200 px-2 text-xs"></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-sky-700">Porsi kecil</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.input_quantity_small" wire:change="updateItemWeights({{ $itemIndex }})" type="number" step=".0001" min=".0001" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-sky-200 bg-white px-2 text-xs"></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-indigo-700">SD 1–3</span><input value="{{ $ingredient['input_quantity_small'] }}" disabled class="h-9 w-full rounded-lg border border-slate-200 bg-slate-100 px-2 text-xs"></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-indigo-700">Porsi besar</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.input_quantity_large" wire:change="updateItemWeights({{ $itemIndex }})" type="number" step=".0001" min=".0001" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-indigo-200 bg-white px-2 text-xs"></label>
                                        <label><span class="mb-1 block text-[10px] font-semibold text-indigo-700">SMP/SMA</span><input value="{{ $ingredient['input_quantity_large'] }}" disabled class="h-9 w-full rounded-lg border border-slate-200 bg-slate-100 px-2 text-xs"></label>
                                        @if ($showSpecialGramasi)
                                            <label><span class="mb-1 block text-[10px] font-semibold text-amber-700">Khusus Balita</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.input_quantity_toddler" wire:change="updateItemWeights({{ $itemIndex }})" type="number" step=".0001" min="0" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-amber-200 bg-amber-50 px-2 text-xs"></label>
                                            <label><span class="mb-1 block text-[10px] font-semibold text-amber-700">Khusus Bumil/Busui</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.input_quantity_maternal" wire:change="updateItemWeights({{ $itemIndex }})" type="number" step=".0001" min="0" @disabled(!$editable || !($ingredient['portion_override'] ?? false)) class="h-9 w-full rounded-lg border border-amber-200 bg-amber-50 px-2 text-xs"></label>
                                        @endif
                                        <input type="hidden" wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.cooking_loss_percent"><input type="hidden" wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.ingredient_portion_standard_id"><input type="hidden" wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.portion_source"><input type="hidden" wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.portion_override">
                                        <div class="xl:col-span-2"><span class="mb-1 block text-[10px] font-semibold text-slate-500">Sumber gramasi</span><div class="flex h-9 items-center rounded-lg border border-slate-200 bg-white px-2 text-[10px] font-bold {{ ($ingredient['portion_source'] ?? '') === 'standard' ? 'text-emerald-700' : 'text-amber-700' }}">{{ ($ingredient['portion_source'] ?? '') === 'standard' ? 'Standar Gizi siswa' : (($ingredient['portion_source'] ?? '') === 'override' ? 'Penyesuaian Ahli Gizi' : 'Manual · tidak ada standar') }}</div></div>
                                        <label class="xl:col-span-3"><span class="mb-1 block text-[10px] font-semibold text-slate-500">Catatan</span><input wire:model="items.{{ $itemIndex }}.ingredients.{{ $ingredientIndex }}.notes" @disabled(!$editable) class="h-9 w-full rounded-lg border border-slate-200 px-2 text-xs"></label>
                                        @if($editable)<div class="flex items-end justify-end gap-2 xl:col-span-3">@if(!($ingredient['portion_override'] ?? false))<button type="button" wire:click="enablePortionOverride({{ $itemIndex }}, {{ $ingredientIndex }})" class="h-9 px-2 text-xs font-bold text-amber-700">Sesuaikan</button>@elseif($ingredient['ingredient_portion_standard_id'] ?? null)<button type="button" wire:click="restoreIngredientStandard({{ $itemIndex }}, {{ $ingredientIndex }})" class="h-9 px-2 text-xs font-bold text-emerald-700">Kembalikan standar</button>@endif<button type="button" wire:click="removeIngredient({{ $itemIndex }}, {{ $ingredientIndex }})" class="h-9 px-2 text-xs font-bold text-rose-600">Hapus</button></div>@endif
                                    </div>
                                @endforeach
                            </div>
                            @if($editable)<div class="mt-4 flex justify-end"><button type="button" wire:click="removeItem({{ $itemIndex }})" wire:confirm="Hapus hidangan beserta resepnya?" class="text-xs font-bold text-rose-600">Hapus hidangan</button></div>@endif
                        </div>
                    </details>
                @endforeach
            </section>

            @if ($editable)
                <div class="sticky bottom-4 z-10 flex justify-end rounded-2xl border border-slate-200 bg-white/90 p-3 shadow-xl backdrop-blur">
                    @if($menu->status === \App\Enums\MenuStatus::Draft && !$menu->cycleDays()->exists() && auth()->user()->can('menus.delete'))<button type="button" wire:click="delete" wire:confirm="Hapus menu draft ini?" class="mr-auto h-10 px-4 text-xs font-bold text-rose-700">Hapus menu</button>@endif
                    <button type="submit" class="h-10 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white">Simpan & hitung gizi</button>
                </div>
            @endif
        </form>

        @if ($activeRevision || $menu->status === \App\Enums\MenuStatus::PendingReview)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Persetujuan revisi menu aktif</h3><textarea wire:model="decisionNotes" rows="2" class="mt-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Catatan keputusan"></textarea><div class="mt-3 flex flex-wrap justify-end gap-2">@if($activeRevision && $menu->isEditable() && auth()->user()->can('menus.submit'))<button wire:click="submitRevision" class="h-10 rounded-xl bg-amber-500 px-4 text-xs font-bold text-white">Ajukan revisi</button>@endif @if($menu->status === \App\Enums\MenuStatus::PendingReview && auth()->user()->can('menus.approve'))<button wire:click="requestRevision" class="h-10 rounded-xl bg-rose-600 px-4 text-xs font-bold text-white">Minta perbaikan</button><button wire:click="approveRevision" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Setujui revisi</button>@endif</div></section>
        @endif
    </div>
</x-v3.shell>
