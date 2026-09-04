<div>
    {{-- Keep an unconditional root before Livewire's conditional morph markers. --}}
    @if ($module === 'distribusi')
        @include('livewire.v3.operations.distribution-index')
    @elseif ($module === 'pencucian')
        @include('livewire.v3.operations.washing-index')
    @elseif ($module === 'kebersihan')
        @include('livewire.v3.operations.cleaning-index')
    @else
        @include('livewire.v3.operations.index-generic')
    @endif
</div>
