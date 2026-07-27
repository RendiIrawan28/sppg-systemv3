@if ($module === 'distribusi')
    @include('livewire.v3.operations.distribution-index')
@else
    @include('livewire.v3.operations.index-generic')
@endif
